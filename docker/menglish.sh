#!/usr/bin/env bash
# Vận hành MEnglish trên Docker theo môi trường.
#   docker/menglish.sh <production|staging> deploy        build image, khởi động, migrate
#   docker/menglish.sh <production|staging> migrate       php artisan migrate --force
#   docker/menglish.sh <production|staging> artisan ...   chạy lệnh artisan bất kỳ
#   docker/menglish.sh <production|staging> import-db <file.sql|file.sql.gz>
#   docker/menglish.sh <production|staging> backup-db     → backups/<env>-<thời gian>.sql.gz
#   docker/menglish.sh <production|staging> <lệnh docker compose khác>   (ps, logs -f, stop scheduler, ...)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_NAME="${1:-}"
case "$ENV_NAME" in
    production|staging) ;;
    *) echo "Dùng: $0 <production|staging> <lệnh>" >&2; exit 1 ;;
esac
shift
ENV_FILE="$ROOT/.env.$ENV_NAME"
[ -f "$ENV_FILE" ] || { echo "Thiếu $ENV_FILE (sao chép từ .env.docker.example)" >&2; exit 1; }

compose() { docker compose -f "$ROOT/docker-compose.yml" --project-directory "$ROOT" --env-file "$ENV_FILE" "$@"; }
env_value() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

ensure_proxy() {
    docker network inspect menglish-proxy >/dev/null 2>&1 || docker network create menglish-proxy >/dev/null
    docker compose -f "$ROOT/docker/proxy/docker-compose.yml" up -d
}

cmd="${1:-ps}"
[ $# -gt 0 ] && shift
case "$cmd" in
    deploy)
        ensure_proxy
        compose build app
        compose up -d --remove-orphans
        compose exec app php artisan migrate --force
        compose exec app php artisan optimize
        compose restart scheduler
        compose ps
        ;;
    migrate)
        compose exec app php artisan migrate --force
        ;;
    artisan)
        compose exec app php artisan "$@"
        ;;
    import-db)
        file="${1:?Cần đường dẫn file .sql hoặc .sql.gz}"
        db="$(env_value DB_DATABASE)"; pass="$(env_value DB_ROOT_PASSWORD)"
        echo "→ Import $file vào $db ($ENV_NAME). Scheduler tạm dừng trong lúc import."
        read -r -p "Database $db ($ENV_NAME) sẽ bị XÓA và thay bằng dữ liệu trong file. Gõ '$ENV_NAME' để tiếp tục: " confirm
        [ "$confirm" = "$ENV_NAME" ] || { echo "Đã hủy."; exit 1; }
        compose stop scheduler
        # Tạo lại DB trống: dump của phpMyAdmin mặc định không có DROP TABLE.
        compose exec -T db mariadb -uroot -p"$pass" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        if [[ "$file" == *.gz ]]; then gunzip -c "$file"; else cat "$file"; fi \
            | compose exec -T db mariadb -uroot -p"$pass" "$db"
        compose exec app php artisan migrate --force
        compose exec app php artisan optimize:clear
        compose restart app
        echo "→ Xong. Bật lại scheduler khi chắc chắn hosting cũ đã tắt cron: $0 $ENV_NAME start scheduler"
        ;;
    backup-db)
        mkdir -p "$ROOT/backups"
        db="$(env_value DB_DATABASE)"; pass="$(env_value DB_ROOT_PASSWORD)"
        out="$ROOT/backups/$ENV_NAME-$(date +%Y%m%d-%H%M%S).sql.gz"
        compose exec -T db mariadb-dump -uroot -p"$pass" --single-transaction --routines --triggers "$db" | gzip > "$out"
        echo "→ $out"
        ;;
    *)
        compose "$cmd" "$@"
        ;;
esac

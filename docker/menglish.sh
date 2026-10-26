#!/usr/bin/env bash
# Vận hành MEnglish trên Docker theo môi trường.
#   docker/menglish.sh <production|staging> deploy [ref]  build image (từ commit/nhánh ref nếu có), khởi động, migrate
#   docker/menglish.sh <production|staging> version       commit đang chạy (khi deploy bằng ref)
#   docker/menglish.sh <production|staging> migrate       php artisan migrate --force
#   docker/menglish.sh <production|staging> artisan ...   chạy lệnh artisan bất kỳ
#   docker/menglish.sh <production|staging> import-db <file.sql|file.sql.gz>
#   docker/menglish.sh <production|staging> backup-db     → backups/<env>-<thời gian>.sql.gz
#   docker/menglish.sh <production|staging> backup        DB + file upload, xóa bản cũ hơn 14 ngày (cron hằng ngày)
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
        # Có ref (commit / nhánh): build từ đúng commit đó qua git archive, không đụng thư mục làm việc.
        # Không có ref: build từ thư mục hiện tại (kể cả thay đổi chưa commit).
        ref="${1:-}"
        ensure_proxy
        if [ -n "$ref" ]; then
            git -C "$ROOT" fetch --quiet origin
            sha="$(git -C "$ROOT" rev-parse --verify "$ref^{commit}")"
            echo "→ Build $ENV_NAME từ commit $sha"
            git -C "$ROOT" archive --format=tar "$sha" \
                | docker build --label "org.opencontainers.image.revision=$sha" -t "menglish-app:$ENV_NAME" -
        else
            compose build app
        fi
        # Scheduler đang dừng (vd. hosting cũ còn chạy cron) thì giữ nguyên trạng thái dừng.
        scheduler_running="$(compose ps --status running -q scheduler)"
        compose up -d --no-build --remove-orphans
        [ -n "$scheduler_running" ] || compose stop scheduler
        compose exec app php artisan migrate --force
        compose exec app php artisan optimize
        [ -z "$scheduler_running" ] || compose restart scheduler
        compose restart queue   # worker đã chạy trước migrate: nạp lại code + cấu hình
        compose ps
        ;;
    version)
        docker image inspect "menglish-app:$ENV_NAME" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
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
    backup-db|backup)
        # backup-db: chỉ DB. backup: DB + file người dùng (public/uploads, storage/app), xóa bản cũ hơn
        # BACKUP_KEEP_DAYS ngày (mặc định 14) — dùng cho cron hằng ngày.
        dir="$ROOT/backups"; stamp="$(date +%Y%m%d-%H%M%S)"
        install -d -m 700 "$dir"
        db="$(env_value DB_DATABASE)"; pass="$(env_value DB_ROOT_PASSWORD)"
        out="$dir/$ENV_NAME-$stamp.sql.gz"
        # Ghi ra file tạm rồi đổi tên: dump lỗi giữa chừng không để lại file trông như bản sao lưu hợp lệ.
        compose exec -T db mariadb-dump -uroot -p"$pass" --single-transaction --routines --triggers "$db" | gzip > "$out.tmp"
        mv "$out.tmp" "$out" && chmod 600 "$out"
        echo "→ $out ($(du -h "$out" | cut -f1))"
        if [ "$cmd" = backup ]; then
            files="$dir/$ENV_NAME-$stamp-files.tar.gz"
            compose exec -T app tar czf - -C /var/www/html public/uploads storage/app > "$files.tmp"
            mv "$files.tmp" "$files" && chmod 600 "$files"
            echo "→ $files ($(du -h "$files" | cut -f1))"
            find "$dir" -maxdepth 1 -name "$ENV_NAME-*" -mtime +"${BACKUP_KEEP_DAYS:-14}" -print -delete
        fi
        ;;
    *)
        compose "$cmd" "$@"
        ;;
esac

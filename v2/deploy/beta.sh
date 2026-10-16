#!/usr/bin/env bash
# Vận hành bản beta của hệ thống mới (React + Go + PostgreSQL) trên máy chủ Docker hiện tại.
#   v2/deploy/beta.sh deploy [ref]   build 2 image (từ commit/nhánh ref nếu có), migrate, khởi động
#   v2/deploy/beta.sh version        commit đang chạy
#   v2/deploy/beta.sh migrate [lệnh] chạy migration (mặc định up; vd. status, down)
#   v2/deploy/beta.sh psql           mở psql vào database beta
#   v2/deploy/beta.sh backup-db      → backups/beta-<thời gian>.sql.gz, xóa bản cũ hơn 14 ngày
#   v2/deploy/beta.sh <lệnh docker compose khác>   (ps, logs -f api, stop, down, ...)
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
V2="$(dirname "$DEPLOY_DIR")"
ROOT="$(dirname "$V2")"
ENV_FILE="$DEPLOY_DIR/.env.beta"
[ -f "$ENV_FILE" ] || { echo "Thiếu $ENV_FILE (sao chép từ .env.beta.example)" >&2; exit 1; }

compose() { docker compose -f "$DEPLOY_DIR/compose.beta.yml" --project-directory "$DEPLOY_DIR" --env-file "$ENV_FILE" "$@"; }
env_value() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

ensure_proxy() {
    docker network inspect menglish-proxy >/dev/null 2>&1 || docker network create menglish-proxy >/dev/null
    docker compose -f "$ROOT/docker/proxy/docker-compose.yml" up -d
}

# build_image <Dockerfile> <tag> <sha|"">: có sha thì build từ đúng commit đó (git archive thư mục v2),
# không đụng thư mục làm việc; không có thì build từ thư mục hiện tại.
build_image() {
    local file="$1" tag="$2" sha="$3"
    if [ -n "$sha" ]; then
        git -C "$ROOT" archive --format=tar "$sha:v2" \
            | docker build -f "deploy/$file" --build-arg "VERSION=$sha" \
                --label "org.opencontainers.image.revision=$sha" -t "$tag" -
    else
        docker build -f "$DEPLOY_DIR/$file" --build-arg "VERSION=$(git -C "$ROOT" rev-parse HEAD)-local" -t "$tag" "$V2"
    fi
}

cmd="${1:-ps}"
[ $# -gt 0 ] && shift
case "$cmd" in
    deploy)
        ref="${1:-}" sha=""
        ensure_proxy
        if [ -n "$ref" ]; then
            git -C "$ROOT" fetch --quiet origin
            sha="$(git -C "$ROOT" rev-parse --verify "$ref^{commit}")"
            echo "→ Build beta từ commit $sha"
        fi
        build_image Dockerfile.api menglish-api:beta "$sha"
        build_image Dockerfile.web menglish-web:beta "$sha"
        # migrate chạy trước (api chờ nó xong); lỗi migrate thì api cũ vẫn chạy.
        compose up -d --no-build --remove-orphans
        for _ in $(seq 1 30); do
            [ "$(compose ps --format '{{.Health}}' api)" = healthy ] && break
            sleep 2
        done
        compose ps
        [ "$(compose ps --format '{{.Health}}' api)" = healthy ] \
            || { echo "api chưa healthy, xem: $0 logs migrate api" >&2; exit 1; }
        # Dọn image không còn dùng để giữ ổ đĩa (image đang chạy không bị xóa).
        docker image prune -f >/dev/null
        ;;
    version)
        docker image inspect menglish-api:beta --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
        ;;
    migrate)
        compose run --rm migrate migrate "${@:-up}"
        ;;
    psql)
        compose exec postgres psql -U "$(env_value POSTGRES_USER)" -d "$(env_value POSTGRES_DB)"
        ;;
    backup-db)
        dir="$ROOT/backups"; stamp="$(date +%Y%m%d-%H%M%S)"
        install -d -m 700 "$dir"
        out="$dir/beta-$stamp.sql.gz"
        # Ghi ra file tạm rồi đổi tên: dump lỗi giữa chừng không để lại file trông như bản sao lưu hợp lệ.
        compose exec -T postgres pg_dump -U "$(env_value POSTGRES_USER)" -d "$(env_value POSTGRES_DB)" --no-owner | gzip > "$out.tmp"
        mv "$out.tmp" "$out" && chmod 600 "$out"
        echo "→ $out ($(du -h "$out" | cut -f1))"
        find "$dir" -maxdepth 1 -name "beta-*" -mtime +"${BACKUP_KEEP_DAYS:-14}" -print -delete
        ;;
    *)
        compose "$cmd" "$@"
        ;;
esac

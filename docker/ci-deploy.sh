#!/usr/bin/env bash
# Điểm vào deploy từ GitHub Actions. Khóa SSH của CI bị ép chạy đúng script này (forced command trong
# ~/.ssh/authorized_keys), nên CI chỉ deploy được, không mở được shell.
#   ssh menglish@server "<production|staging|beta> <commit sha 40 ký tự>"
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
read -r ENV_NAME SHA EXTRA <<< "${SSH_ORIGINAL_COMMAND:-${*:-}}"

case "$ENV_NAME" in
    production|staging|beta) ;;
    *) echo "Môi trường không hợp lệ: '$ENV_NAME'" >&2; exit 2 ;;
esac
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] && [ -z "${EXTRA:-}" ] || { echo "Cần đúng 1 commit sha 40 ký tự" >&2; exit 2; }

# Mỗi lần chỉ một deploy (cả 2 môi trường dùng chung thư mục mã nguồn).
exec 9>"$ROOT/.ci-deploy.lock"
flock -w 900 9 || { echo "Đang có deploy khác chạy quá 15 phút" >&2; exit 3; }

git -C "$ROOT" fetch --quiet origin
git -C "$ROOT" cat-file -e "$SHA^{commit}" 2>/dev/null || { echo "Không thấy commit $SHA trên origin" >&2; exit 2; }

if [ "$ENV_NAME" = production ]; then
    git -C "$ROOT" merge-base --is-ancestor "$SHA" origin/main \
        || { echo "Production chỉ nhận commit đã nằm trên main" >&2; exit 2; }
    # Cập nhật compose / script vận hành theo main (thư mục làm việc luôn ở main, không sửa tay trên server).
    git -C "$ROOT" checkout --quiet main
    git -C "$ROOT" merge --quiet --ff-only origin/main
fi

if [ "$ENV_NAME" = beta ]; then
    # Bản beta hệ thống mới (v2/): build từ đúng commit qua git archive, không đổi thư mục làm việc.
    "$ROOT/v2/deploy/beta.sh" deploy "$SHA"
else
    "$ROOT/docker/menglish.sh" "$ENV_NAME" deploy "$SHA"
fi

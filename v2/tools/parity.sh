#!/usr/bin/env bash
# Đối chiếu v2 với Laravel theo v2/doi-chieu-laravel.txt.
#   v2/tools/parity.sh            liệt kê mảng v2 có file Laravel đã đổi kể từ commit đã đối chiếu
#   v2/tools/parity.sh <base>     thêm: cảnh báo nếu nhánh hiện tại (so với <base>) sửa file Laravel của mảng đã chuyển
# Chỉ cảnh báo, luôn thoát 0. Trên GitHub Actions in ::warning và ghi tóm tắt vào $GITHUB_STEP_SUMMARY.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

base="${1:-}"
manifest=v2/doi-chieu-laravel.txt
summary="${GITHUB_STEP_SUMMARY:-/dev/null}"
found=0

{ echo "## Đối chiếu v2 với Laravel"; echo; } >> "$summary"

while read -r area commit files; do
  [[ -z "$area" || "$area" == \#* ]] && continue
  read -ra paths <<< "$files"

  if [[ -n "$base" ]]; then
    touched=$(git diff --name-only "$base"...HEAD -- "${paths[@]}")
    if [[ -n "$touched" ]]; then
      found=1
      msg="PR này sửa file Laravel mà v2 (mảng $area) đang bám theo: $(echo $touched). Cần cập nhật v2 tương ứng, rồi sửa commit của $area trong $manifest."
      echo "::warning title=Cần cập nhật v2 ($area)::$msg"
      echo "- **$area**: $msg" >> "$summary"
    fi
  fi

  if ! git cat-file -e "$commit^{commit}" 2>/dev/null; then
    echo "::warning title=Đối chiếu v2::Không tìm thấy commit $commit của mảng $area (cần fetch-depth: 0)."
    continue
  fi
  changed=$(git diff --name-only "$commit" HEAD -- "${paths[@]}")
  if [[ -n "$changed" ]]; then
    found=1
    echo "Mảng $area: Laravel đã đổi từ ${commit:0:8}:"
    echo "$changed" | sed 's/^/  /'
    echo "  Xem: git diff ${commit:0:8} HEAD -- $(echo $changed)"
    echo "- **$area**: Laravel đã đổi từ \`${commit:0:8}\`: $(echo $changed)" >> "$summary"
  fi
done < "$manifest"

if [[ $found == 0 ]]; then
  echo "v2 khớp với Laravel ở mọi mảng đã chuyển."
  echo "v2 khớp với Laravel ở mọi mảng đã chuyển." >> "$summary"
fi

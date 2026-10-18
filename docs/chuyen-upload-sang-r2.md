# Chuyển file tải lên sang Cloudflare R2

Mọi file người dùng tải lên nằm trong ba disk, bật R2 bằng một biến `UPLOAD_STORAGE=r2`:

| Disk | Trên máy chủ (`UPLOAD_STORAGE=local`) | Trên R2 (`UPLOAD_STORAGE=r2`) | Mở bằng |
|---|---|---|---|
| `local` (riêng tư) | `storage/app/private` | `private/` | route có kiểm quyền (ảnh chấm công, hợp đồng, ticket, giáo trình, minh chứng phạt / hoàn phí, biên bản SLA) |
| `public` | `storage/app/public` | `storage/` | `/storage/...` (CV, minh chứng đầu việc, ảnh báo cáo lớp, bài nộp, ghi âm, đề test đầu vào) |
| `uploads` | `public/uploads` | `uploads/` | `/uploads/...` (chứng từ học phí, ảnh hóa đơn giấy, Quản lý Media, đính kèm ticket cũ) |

Bucket để riêng tư, không bật public access. `/storage/...` và `/uploads/...` không còn file trên máy chủ thì được chuyển hướng sang URL ký của R2 (còn hạn ít nhất 1 giờ), nên đường dẫn cũ đã lưu trong DB vẫn mở được. File riêng tư vẫn đi qua Laravel như trước.

## 1. Tạo bucket và khóa trên Cloudflare

1. R2 → **Create bucket**: mỗi môi trường một bucket, vd. `menglish-production`, `menglish-staging`. Staging không bao giờ dùng chung bucket với production (xóa file ở staging sẽ xóa file thật).
2. R2 → **Manage API tokens** → **Create API token**: quyền *Object Read & Write*, chỉ cho bucket của môi trường đó. Ghi lại *Access Key ID*, *Secret Access Key* và endpoint `https://<account-id>.r2.cloudflarestorage.com`.
3. Bucket → **Settings → CORS policy** (trình xem PDF của đề test đọc file bằng JavaScript):

```json
[
  {
    "AllowedOrigins": ["https://portal.meducation.vn"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["Content-Length", "Content-Range", "Accept-Ranges"],
    "MaxAgeSeconds": 3600
  }
]
```

Bucket staging thì thay origin bằng tên miền staging.

## 2. Khai báo khóa, chép file cũ, rồi bật R2

Thêm vào `.env.production` trên máy chủ (giữ `UPLOAD_STORAGE=local` ở bước này):

```
UPLOAD_STORAGE=local
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_BUCKET=menglish-production
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
```

```bash
docker/menglish.sh production deploy                                   # bản code có lệnh này + nạp biến mới
docker/menglish.sh production artisan uploads:copy-to-r2 --dry-run    # xem số file / dung lượng sẽ chép
docker/menglish.sh production artisan uploads:copy-to-r2              # chép (chạy lại được, file đã có thì bỏ qua)
```

Đổi `UPLOAD_STORAGE=r2` trong `.env.production`, rồi:

```bash
docker/menglish.sh production up -d --force-recreate app scheduler    # nạp lại biến môi trường
docker/menglish.sh production artisan optimize
docker/menglish.sh production artisan uploads:copy-to-r2              # chép nốt file tải lên trong lúc chuyển
```

Kiểm tra: mở một ảnh chấm công, một file đính kèm ticket, một đề test PDF, Quản lý Media, tải thử một file mới.

## 3. Dọn file trên máy chủ (sau vài ngày chạy ổn)

```bash
docker/menglish.sh production artisan uploads:copy-to-r2 --delete-local
```

Chỉ xóa file mà bản trên R2 đã khớp dung lượng. Từ lúc này `docker/menglish.sh production backup` không còn chứa file tải lên (file nằm trên R2).

## Quay lại máy chủ

Đặt lại `UPLOAD_STORAGE=local` rồi recreate như trên. File tải lên trong thời gian dùng R2 chỉ nằm trên R2: chép về trước (vd. `rclone copy`) nếu cần.

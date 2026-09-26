# Deploy MEnglish lên hosting DirectAdmin

Hosting: DirectAdmin (`https://hostingvds.vmst.com.vn:2222`). Không cần SSH. Code được build trên GitHub Actions và upload qua FTP; lệnh `migrate` / làm mới cache chạy qua một hook có token bí mật.

## Cấu trúc thư mục trên hosting

```
/home/<user>/domains/<domain>/
├── menglish/          ← code Laravel (app, vendor, storage, .env …) — KHÔNG truy cập được từ web
└── public_html/       ← chỉ nội dung thư mục public/ (index.php trỏ sang ../menglish)
```

Mỗi môi trường (staging `dungthu…`, production `portal…`) là một domain riêng với cấu trúc như trên.

## Cài đặt lần đầu (làm 1 lần cho mỗi domain)

1. **PHP**: DirectAdmin → *Select PHP version* (hoặc *PHP Version Selector*) → chọn **PHP 8.2 trở lên** (khuyến nghị 8.3). Bật extension: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `fileinfo`, `openssl`, `curl`.
2. **Database**: DirectAdmin → *MySQL Management* → tạo database + user (ghi lại tên DB, user, mật khẩu).
3. **Tài khoản FTP**: DirectAdmin → *FTP Management* → tạo tài khoản FTP có quyền vào `domains/<domain>/` (hoặc dùng tài khoản chính). Ghi lại host FTP, user, mật khẩu.
4. **File `.env`**: tạo trên máy từ `.env.example`, điền:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`
   - `APP_KEY=` sinh bằng `php artisan key:generate --show` (trên máy có PHP)
   - `DB_HOST=localhost`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (bước 2)
   - `DEPLOY_HOOK_TOKEN=` chuỗi ngẫu nhiên ≥ 32 ký tự: `php -r "echo bin2hex(random_bytes(32));"`
   - Mail SMTP, SePay (khóa **mới** — khóa cũ đã lộ trong nhật ký, phải đổi)

   Upload `.env` vào `domains/<domain>/menglish/.env` bằng *File Manager* của DirectAdmin (sau lần deploy đầu tạo ra thư mục `menglish/`), phân quyền 640.
5. **GitHub**: repo → *Settings → Environments* → tạo `staging` và `production`, mỗi môi trường thêm **Secrets**:

   | Secret | Ví dụ |
   |---|---|
   | `FTP_SERVER` | `ftp.<domain>` hoặc IP hosting |
   | `FTP_USERNAME` / `FTP_PASSWORD` | tài khoản FTP bước 3 |
   | `FTP_APP_DIR` | `domains/<domain>/menglish/` |
   | `FTP_PUBLIC_DIR` | `domains/<domain>/public_html/` |
   | `APP_URL` | `https://<domain>` |
   | `DEPLOY_HOOK_TOKEN` | giống giá trị trong `.env` |

   *Variables* (tùy chọn): `PHP_VERSION` (khớp PHP hosting, mặc định `8.3`), `APP_DIR_NAME` (mặc định `menglish`).
   Nên bật *Required reviewers* cho môi trường `production`.
6. **Cron**: DirectAdmin → *Cron Jobs* → thêm lệnh chạy **mỗi phút** (`* * * * *`):
   ```
   /usr/local/bin/php /home/<user>/domains/<domain>/menglish/artisan schedule:run >> /dev/null 2>&1
   ```
   (đường dẫn PHP xem trong DirectAdmin; nếu chọn PHP khác mặc định thường là `/usr/local/php83/bin/php`). Cron chạy: nhắc Big Test, nhắc nợ, chăm sóc tháng đầu, kết thúc bảo lưu, việc quá hạn, hợp đồng sắp hết hạn…

## Staging mới (database trống)

Chạy workflow với **`seed` = tick**: sau migrate, hook chạy `db:seed` (vai trò, quyền, chi nhánh, tài khoản mặc định; với `APP_ENV=staging` có thêm dữ liệu demo — xem README "Kiểm tra nhanh toàn hệ thống" để biết tài khoản). Mật khẩu mặc định = `SEED_DEFAULT_PASSWORD` trong `.env` (mặc định `Password123!` — nên đặt giá trị khác). Tùy chọn này **bị chặn trên production**.

## Mỗi lần deploy

GitHub → *Actions* → **Deploy hosting (DirectAdmin)** → *Run workflow* → chọn `staging` hoặc `production`.

Workflow sẽ: chạy toàn bộ test → `composer install --no-dev` → build CSS/JS → upload code vào `menglish/` và `public/` vào `public_html/` (chỉ file thay đổi; **không** động tới `.env`, `storage`, file người dùng upload) → gọi hook chạy `migrate --force`, `storage:link`, làm mới cache → kiểm tra trang `/login` trả 200.

## Trước lần deploy đầu tiên lên production

- **Sao lưu database** (DirectAdmin → *MySQL Management* → *Backup*) — đợt này có nhiều migration chuyển dữ liệu (CRM, giáo trình theo chặng, mốc chăm sóc, lương, phân quyền).
- Deploy **staging** trước, kiểm tra các luồng chính (xem README "Kiểm tra nhanh toàn hệ thống").
- **Không** chạy `db:seed` trên production (dữ liệu demo chỉ cho local/staging).
- Sau deploy: nhập tài khoản ngân hàng từng chi nhánh trước khi bật SePay; kiểm tra quyền (kế toán tổng có "Xem & xử lý học phí mọi chi nhánh", Quản lý / Học vụ đã gán chi nhánh); tài khoản Zalo OA / ZNS khi có.

## Xử lý sự cố

- Hook trả 404: `.env` chưa có `DEPLOY_HOOK_TOKEN` (≥ 32 ký tự) hoặc token trên GitHub khác `.env`.
- Hook trả 500: xem `steps` trong log của bước *Migrate + cache (hook)* và `menglish/storage/logs/laravel.log`.
- Trang trắng / 500 ngay sau upload lần đầu: kiểm tra `.env` đã có trong `menglish/`, phiên bản PHP, quyền ghi thư mục `menglish/storage` và `menglish/bootstrap/cache` (755/775).

# MEnglish v2 (React + Go + PostgreSQL)

Hệ thống mới theo [tài liệu thiết kế](../docs/thiet-ke-he-thong-moi.md), làm ngay trong repo này và chạy song song với Laravel. Laravel vẫn là production cho tới khi v2 đủ chức năng; khi đó chuyển hẳn một lần (dữ liệu MariaDB nạp sang PostgreSQL bằng `menglish import-legacy`).

```
v2/
├── api/      Go: một binary `menglish` (api | migrate | user | healthcheck | version)
├── tools/    parity.sh: đối chiếu v2 với Laravel
├── web/      React 19 + Vite + TanStack Query + Tailwind 4
└── deploy/   Dockerfile, Caddyfile của web, compose beta, script beta.sh
```

## Chạy ở máy dev

```bash
# Postgres + Redis
docker run -d --name mg-pg -e POSTGRES_PASSWORD=dev -p 5432:5432 postgres:18-alpine
docker run -d --name mg-redis -p 6379:6379 redis:8-alpine

cd v2/api
export DATABASE_URL=postgres://postgres:dev@localhost:5432/postgres?sslmode=disable REDIS_URL=redis://localhost:6379/0
export APP_ENCRYPTION_KEY=$(openssl rand -base64 32)
go run ./cmd/menglish migrate up
go run ./cmd/menglish user create --code MES001 --email admin@example.com --name "Quản trị" --role admin   # in mật khẩu tạm
go run ./cmd/menglish api            # http://localhost:8080/readyz

cd v2/web && npm ci && npm run dev   # http://localhost:5173, /api chuyển sang Go
```

Test: `go test -p 1 ./...` (đặt `TEST_DATABASE_URL` và `TEST_REDIS_URL`, vd. `redis://localhost:6379/15`, để chạy cả test đăng nhập với DB thật; test xóa sạch DB Redis đó), `npm test`, `npm run build`. Sửa migration hoặc `internal/iam/queries.sql` thì chạy `sqlc generate` trong `v2/api`. CI: `.github/workflows/v2-ci.yml`, chỉ chạy khi `v2/` thay đổi.

## Bản beta trên máy chủ hiện tại

```
Caddy chung (docker/proxy) ─┬─▶ menglish-production   portal.meducation.vn   (Laravel)
                            ├─▶ menglish-staging      dungthu.meducation.vn  (Laravel)
                            └─▶ menglish-beta: web ─▶ api ─▶ postgres, redis   beta.meducation.vn
```

Giới hạn RAM của cụm beta khoảng 1 GB (postgres 512 MB, api 256 MB, redis 128 MB, web 64 MB); đo thử lúc vừa khởi động dùng khoảng 60 MB. Postgres chỉ mở `127.0.0.1:5433` cho SSH tunnel.

### Cài lần đầu

1. Cập nhật mã nguồn trên máy chủ sau khi merge (`git pull` ở thư mục repo).
2. Tạo file cấu hình:
   ```bash
   cp v2/deploy/.env.beta.example v2/deploy/.env.beta && chmod 600 v2/deploy/.env.beta
   # đổi POSTGRES_PASSWORD, vd. openssl rand -hex 24
   ```
3. Thêm `BETA_SITE=beta.meducation.vn` vào `docker/proxy/.env`, rồi tạo lại Caddy để nhận Caddyfile và domain mới (portal / dungthu gián đoạn vài giây):
   ```bash
   docker compose -f docker/proxy/docker-compose.yml up -d --force-recreate
   ```
4. Cloudflare: bản ghi `beta` giống `portal` / `dungthu` (Full strict). Caddy tự lấy chứng chỉ.
5. Deploy lần đầu: `v2/deploy/beta.sh deploy origin/main`. Lần deploy đầu tự thêm `APP_ENCRYPTION_KEY` vào `.env.beta`; hãy sao lưu file này, mất khóa thì mọi người phải bật lại 2FA.
6. Tạo tài khoản Admin đầu tiên, lệnh in ra mật khẩu tạm (bắt đổi ở lần đăng nhập đầu):
   ```bash
   v2/deploy/beta.sh user create --code MES001 --email admin@meducation.vn --name "Họ tên" --role admin
   ```
   Tài khoản tạo trước bước 2 (chưa có vai trò) thì gán vai trò Admin: `v2/deploy/beta.sh user set-roles MES001 admin`. Admin phải bật xác thực hai bước ở lần đăng nhập tới.

### Lệnh thường dùng

```bash
v2/deploy/beta.sh deploy [ref]      # build từ commit / nhánh, migrate, khởi động (không có ref: build từ thư mục hiện tại)
v2/deploy/beta.sh version
v2/deploy/beta.sh ps
v2/deploy/beta.sh logs -f api
v2/deploy/beta.sh migrate status
v2/deploy/beta.sh psql
v2/deploy/beta.sh user create --code MES002 --name "..." [--email ...] [--phone ...] [--kind staff|student|guardian] [--role admin]
v2/deploy/beta.sh user set-roles MES002 manager,teacher_fulltime
v2/deploy/beta.sh user reset-password MES002    # mật khẩu tạm mới, mở khóa, đăng xuất mọi thiết bị
v2/deploy/beta.sh backup-db         # → backups/beta-<thời gian>.sql.gz, giữ 14 ngày
v2/deploy/beta.sh stop              # tạm tắt beta để nhường RAM cho Laravel
```

Deploy bằng GitHub Actions: *Deploy server* → *Run workflow* → chọn **beta** (mọi nhánh). Máy chủ build image từ đúng commit qua `git archive`, giống staging.

## Đăng nhập (giai đoạn 0, bước 1)

- Đăng nhập bằng email, mã (nhân sự `MES001`, học viên `HV-00001`) hoặc SĐT phụ huynh, luôn kèm mật khẩu.
- Mật khẩu argon2id; ít nhất 10 ký tự, chặn mật khẩu phổ biến và mật khẩu chứa mã / email / SĐT; mật khẩu tạm phải đổi ở lần đầu.
- Phiên trong Redis (`session:<sha256 token>`), cookie `__Host-session` (HttpOnly, Secure, SameSite=Lax), hết hạn trượt 8 giờ hoặc 30 ngày khi ghi nhớ. Mọi request ghi cần header `X-CSRF-Token` và `Origin` cùng trang.
- Sai 5 lần mỗi tài khoản + IP hoặc 30 lần mỗi IP trong 10 phút thì chặn; sai 10 lần liên tiếp thì khóa tài khoản 15 phút. Thông báo lỗi không cho biết tài khoản có tồn tại hay không.
- 2FA bằng TOTP (ứng dụng xác thực), 10 mã dự phòng dùng một lần; khóa TOTP mã hóa AES-256-GCM bằng `APP_ENCRYPTION_KEY`.
- Đổi mật khẩu thì đăng xuất các thiết bị khác; người dùng xem và đăng xuất từng thiết bị.
- Chưa có: nhật ký thao tác và cảnh báo Admin (bước 5).

## Vai trò và quyền (giai đoạn 0, bước 2)

Giữ nguyên mô hình của bản Laravel (`docs/rbac.md`):

- 9 vai trò cố định và danh mục quyền lấy thẳng từ `config/permission_catalog.php`, `config/access.php`: `php api/internal/iam/catalog/export.php > api/internal/iam/catalog/catalog.json` (chạy từ gốc repo; CI báo nếu lệch). `menglish migrate up` đồng bộ danh mục xuống DB; quyền mặc định chỉ cấp cho vai trò vừa tạo, không ghi đè cấu hình Admin đã chỉnh.
- Thứ tự quyết định: Admin được mọi quyền thao tác và phạm vi (trừ quyền đối tượng) → quyền duyệt chỉ Admin → phân quyền cá nhân (cho phép / chặn) → quyền của vai trò. Tập quyền đã tính cache trong Redis 10 phút, bỏ ngay khi đổi vai trò, phân quyền hoặc chi nhánh.
- Phạm vi dữ liệu `module.scope_own|branch|all`: `Access.Level`, `Access.BranchLimit`, `Access.CoversBranch`. Chi nhánh của người dùng = chi nhánh chính + chi nhánh cấp thêm.
- Cây vai trò: chỉ quản lý được người mà mọi vai trò của họ mình gán được (`user.assign_role.<vai trò>`); Quản lý cơ sở gán mọi vai trò trừ Admin và Quản lý cơ sở; Học vụ gán GV, Trợ giảng, Học viên, Học thuật. Không ai tự bỏ được quyền quản trị phân quyền của mình.
- Bắt buộc 2FA với Admin và người có `role.assign_permission`, `permission.override`, `user.assign_role`, quyền chạy lương; chưa bật thì mọi API nghiệp vụ trả `totp_setup_required` và không tắt được 2FA.
- Mọi route nghiệp vụ gắn `iam.Require(quyền)`: middleware này kiểm luôn đăng nhập, CSRF, đổi mật khẩu lần đầu, 2FA bắt buộc. `TestEveryRouteHasPermission` đỏ nếu route nào thiếu; tên quyền gõ sai thì chương trình dừng ngay lúc khởi động.
- Menu theo quyền: `web/src/nav.ts`.

## Chấm công theo chi nhánh

- Màn Chi nhánh có cột "Chấm công" và hộp cài đặt giống Laravel: tọa độ, bán kính (20–5000 m), giờ vào / ra, số phút cho phép muộn.
- Tọa độ chọn trên bản đồ OpenStreetMap (Leaflet), tìm theo địa chỉ, dán "vĩ độ, kinh độ" từ Google Maps hoặc lấy vị trí hiện tại.
- Tìm địa chỉ đi qua API (`GET /api/v1/branches/address-search`, gói `internal/geo`): Nominatim trước, Photon sau, cache Redis 30 ngày, 30 lần / phút mỗi người. Máy chủ cần ra được `nominatim.openstreetmap.org` và `photon.komoot.io`; không ra được thì màn báo lỗi và vẫn bấm bản đồ / dán tọa độ được.
- `PUBLIC_URL` (compose đặt sẵn theo `BETA_SITE`) được gửi kèm User-Agent theo chính sách của Nominatim.

## Bám theo Laravel trong lúc làm v2

Laravel vẫn chạy production và được sửa bình thường. `doi-chieu-laravel.txt` ghi mỗi mảng v2 dựa trên file Laravel nào, đã đối chiếu tới commit nào.

- Workflow *Đối chiếu v2 với Laravel* chạy trên mọi PR: PR sửa file Laravel của mảng đã chuyển thì hiện cảnh báo "Cần cập nhật v2" (không chặn merge).
- Xem mảng nào đang lệch: `v2/tools/parity.sh`, rồi `git diff <commit> HEAD -- <file>` để biết cần bổ sung gì. Cập nhật v2 xong thì sửa commit trong `doi-chieu-laravel.txt`.
- Dữ liệu không đối chiếu tay: `menglish import-legacy` (bước 4) đọc thẳng DB Laravel, chạy lại được nhiều lần; trước khi chuyển hẳn một mảng thì khóa mảng đó trên Laravel và import lần cuối.

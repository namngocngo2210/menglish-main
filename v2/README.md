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
- Quên mật khẩu (`/forgot-password`): gửi link qua email, luôn cùng một thông báo; link dùng một lần, hạn 30 phút, token
  lưu dạng băm trong Redis; đặt lại xong thì đăng xuất mọi thiết bị.
- Thao tác ghi trên `/auth` (đổi mật khẩu, bật / tắt 2FA, đăng xuất thiết bị) ghi một dòng chung vào nhật ký vận hành, không kèm dữ liệu gửi lên. Chưa có: cảnh báo Admin.

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

## Nhập dữ liệu từ Laravel (import-legacy)

```bash
v2/deploy/beta.sh import-legacy production --dry-run   # chạy thử, in báo cáo, không ghi gì
v2/deploy/beta.sh import-legacy production             # nhập thật
v2/deploy/beta.sh import-legacy production --only branches,users
```

- Chỉ đọc DB Laravel (nối vào mạng `menglish-<môi trường>_default` để gọi container `db`, tài khoản DB lấy từ `.env.<môi trường>`).
- Chạy lại bao nhiêu lần cũng được: bảng `legacy.id_map` nối id cũ với uuid mới, bản ghi đã nhập thì cập nhật theo Laravel. Trước khi chuyển một phân hệ sang v2: khóa ghi bên Laravel, chạy lần cuối.
- Hiện nhập: chi nhánh (kể cả cài đặt chấm công), tài khoản (mã, email, SĐT, trạng thái, mật khẩu), vai trò (vai trò đã bỏ `accountant` → Quản lý cơ sở, `teacher` → GV Full-time), chi nhánh của tài khoản, ma trận quyền theo vai trò, phân quyền cá nhân (phạm vi lớp chờ phân hệ lớp học).
- Mật khẩu: giữ chuỗi bcrypt của Laravel; lần đăng nhập đầu trên v2 tự băm lại bằng argon2id. Tài khoản đã đổi mật khẩu trên v2 thì lần nhập sau không ghi đè. Tài khoản tạo tay trên v2 trùng mã / email được nhận làm một (giữ mật khẩu và 2FA của v2).
- Thêm phân hệ: viết `LegacySteps()` trong gói của phân hệ (xem `internal/legacy`, `internal/iam/legacy_import.go`) rồi nối vào `legacySteps()` ở `cmd/menglish/importlegacy.go`.
- CI chạy migration Laravel lên MariaDB rồi chạy test nhập (`TEST_LEGACY_MYSQL_DSN`): bảng Laravel đổi cột thì CI của v2 đỏ, kể cả PR chỉ sửa `database/migrations`.
## Nền tảng: khung giao diện, nhật ký vận hành, thông báo, tìm kiếm

Đối chiếu từng phần với Laravel: `doi-chieu/nen-tang.md`.

### Khung giao diện và menu (`web/src/nav.ts`, `web/src/components/Shell.tsx`)

- Như `AppLayout.vue` + `SidebarMenu.php`: sidebar tối chia khu (Phê duyệt, Giao việc, Tuyển sinh, Đào tạo, Tài chính, Người dùng, Báo cáo, Cá nhân), thu gọn được (nhớ trong trình duyệt), ngăn kéo trên điện thoại; topbar có tiêu đề trang, ô tìm kiếm (Ctrl K), chuông thông báo, menu tài khoản; tab của workspace hoặc menu Cài đặt ở đầu nội dung.
- Module mới chỉ thêm mục vào `NAV` (và một dòng trong `routes.tsx`): `{ path, label, perms, group, hidden?, icon? }`. `group` là tên workspace trong `WORKSPACES` (vd. `'Khách hàng (CRM)'`, `'Học viên'`, `'Của tôi'`): mỗi workspace là một dòng sidebar, các mục cùng workspace hiện thành tab. Màn cấu hình dùng nhóm Cài đặt: `'Tổ chức & Đào tạo'`, `'Lương'`, `'Hệ thống'` hoặc `'Cài đặt › <nhóm>'` (vd. `'Cài đặt › Học phí'`). Nhóm lạ rơi vào khu "Khác". `hidden: true`: có trang nhưng không hiện trên menu (vd. `/search`).
- Biểu tượng Material Symbols tự host (`web/src/assets/fonts`, không dùng CDN vì CSP chỉ cho `'self'`): `<Icon name="..." />` trong `components/ui.tsx`.

### Nhật ký vận hành (`api/internal/audit`, màn `/activity-logs`)

Ghi trong transaction của thao tác (lỗi thì rollback cùng thao tác), mô tả tiếng Việt như `Audit::describe` của Laravel:

```go
err := pgx.BeginFunc(ctx, pool, func(tx pgx.Tx) error {
    // ... ghi dữ liệu ...
    return audit.Record(ctx, tx, audit.Entry{
        ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated, Action: "student.update",
        SubjectType: "student", SubjectID: id.String(), SubjectLabel: "Nguyễn Văn A (HV-00001)", BranchID: &branchID,
        Description: "Cập nhật hồ sơ học viên",
        Changes: audit.Diff(before, after), // map[string]any trước / sau; trường nhạy cảm tự che ***
    })
})
```

- `Module`: dùng hằng `audit.Module*` (tên phân hệ như `log_name` của Laravel, nhóm lọc nhanh theo `audit.Groups`). `Event`: `created | updated | deleted | restored` hoặc tên thao tác. `Description` bắt buộc.
- `audit.RegisterSubject("student", "Học viên")`: tên loại đối tượng trên màn chi tiết. `audit.RegisterPath("/students", audit.ModuleStudents)`: phân hệ cho dòng ghi tự động.
- `audit.Middleware` (gắn sẵn trong `cmd/menglish`) lưu IP / User-Agent / đường dẫn / mã request cho `Record`, và tự ghi một dòng chung cho mọi request ghi thành công của người đã đăng nhập mà handler không gọi `Record` (như `AuditOperationMiddleware`), nên module chưa gọi `Record` vẫn có dấu vết.
- Giá trị nhạy cảm (mật khẩu, token, khóa, mã 2FA, CCCD…) che bằng `***` (`audit.IsSensitiveKey`, như `SensitiveData`).
- Màn hình: quyền `activity_log.view`, phạm vi `activity_log.scope_own|branch|all` (của tôi / nhân sự chi nhánh của tôi / tất cả), lọc theo nhóm, phân hệ, người, loại, khoảng ngày, tìm kiếm, xuất CSV.

### Thông báo (`api/internal/notify`, chuông trên topbar, màn `/notifications`)

```go
ids, err := iam.UserIDsWithPermission(ctx, tx, "tuition.approve", &branchID) // hoặc iam.AdminIDs(ctx, tx), hoặc id cụ thể
_, err = notify.Send(ctx, tx, ids, notify.Notification{
    Type: "receipt_pending", Title: "Phiếu thu PT-00012 chờ duyệt", Body: "Học viên Nguyễn Văn A · 3.000.000đ",
    Link: "/tuition/receipts/<id>", CreatedBy: &actor.UserID, Except: &actor.UserID,
})
```

- Mỗi người nhận một dòng riêng (trạng thái đã đọc riêng). `Send` bỏ trùng, bỏ người trong `Except`, bỏ tài khoản đã khóa; gửi trong transaction thì rollback cùng nghiệp vụ.
- `Link` chỉ nhận đường dẫn trong ứng dụng (`/...`). `Data` (tùy chọn) là dữ liệu kèm cho màn hình: `customer_id`, `customer_name`, `customer_phone`, `assigned_user`, `hours_elapsed` (thẻ lead tồn đọng), `copy_text` (nút sao chép nội dung gửi phụ huynh).
- Loại thông báo của Laravel (nhãn, biểu tượng, màu) khai báo sẵn trong `notify`; loại mới: `notify.RegisterType("x", notify.TypeInfo{Label, Icon, Tone})` trong `init()` của module.
- API của người đang đăng nhập (quyền `notification.view`): `GET /notifications?type=&unread=1&page=`, `GET /notifications/summary` (số chưa đọc + 6 tin mới nhất cho chuông), `POST /notifications/{id}/read`, `POST /notifications/read-all`.

### Tìm kiếm chung (`api/internal/search`, bảng Ctrl K, màn `/search`)

- Màn hình theo tên tìm ở giao diện (`searchScreens` trong `nav.ts`, chỉ màn người dùng mở được).
- Dữ liệu tìm qua `GET /search?q=&limit=` (ít nhất 2 ký tự): mỗi module đăng ký một nhóm, nhóm chỉ chạy khi người dùng có quyền của nhóm và tự lọc theo phạm vi dữ liệu. Có sẵn: người dùng (`user.view`, cùng phạm vi màn Người dùng), chi nhánh (`branch.view`). Thêm nhóm trong `cmd/menglish/main.go`:

```go
search.Builtin(d.iam).Add(search.Searcher{
    Key: "students", Label: "Học viên", Icon: "school", Perms: []string{"student.view"},
    Search: func(ctx context.Context, actor *iam.Access, q search.Query) ([]search.Hit, error) {
        // q.Like = "%từ khóa%" đã thoát ký tự LIKE; q.Phone = "%chữ số%" khi có từ 3 chữ số; q.Limit
        // trả []search.Hit{ID, Title, Code, Subtitle, Meta, Link}, đã lọc theo phạm vi của actor
    },
})
```

### Tổng quan (`api/internal/dashboard`, màn `/`, Báo cáo & sự vụ lớp `/academic/dashboards`)

- `GET /dashboard?month=` (mọi nhân sự, `portal.staff`), `GET /academic/dashboards/reports?tab=`, `GET /academic/dashboards/incidents` (`class.update`). Chỉ đọc; trang tài khoản ở `/profile`.
- Gói `dashboard` không đọc bảng của module khác: mỗi nguồn là interface trong `dashboard/sources.go`, module nguồn cài bằng `Dashboard<Tên>()` với SQL riêng trong `<module>/dashboard.sql` (khai báo thêm vào `queries` của module trong `sqlc.yaml`), nối trong `cmd/menglish/main.go`. Nguồn chưa có (học phí `Finance`, chấm công & KPI `StaffWork`) mặc định trả "Chờ phân hệ …"; khi gộp chỉ cần truyền vào `dashboard.Deps`.

### Test tích hợp dùng chung (`api/internal/iam/iamtest`)

`iamtest.Setup(t, "<bảng của module cần xóa>")` migrate DB test, xóa dữ liệu, đồng bộ quyền; `e.Serve(handler.Routes)`; `e.Branch`, `e.Member(code, tên, chi nhánh, vai trò...)` (tạo tài khoản + đăng nhập, tự bật 2FA khi vai trò bắt buộc), `e.Grant / e.Revoke` quyền của vai trò, `e.Anon()`; `c.Do(method, path, body)`, `iamtest.Expect(t, r, status, code)`.

## Gửi email (`api/internal/notify/mail`)

Module không gửi thư trực tiếp: xếp thư vào `core.mail_outbox` trong transaction nghiệp vụ, vòng gửi nền gửi sau khi
commit (thử lại 1, 2, 4… phút, tối đa 1 giờ). Hòm thư SMTP lấy từ màn Email nhận Ticket (mật khẩu mã hóa bằng
`APP_ENCRYPTION_KEY`). Màn **Hàng đợi email** (`/system-config/mail-outbox`, quyền `mail_config.manage`) xem thư lỗi và
gửi lại.

```go
_, err := mail.Enqueue(ctx, tx, mail.ParentNotice(email, "Xác nhận …", body, "/duong-dan", "Xem chi tiết"))
_, err = mail.OperationalAlert(ctx, tx, mail.EventTransaction, subject, content, mail.Alert{Code: "PT-0001"})
```

- `MAIL_BLOCK_OUTGOING=true` chặn mọi thư ra ngoài (thư chuyển "Bị chặn"); mặc định bật trừ khi `MENGLISH_ENV=production`.
- Test: `mail.FakeTransport` + `mail.NewService(...).ProcessDue(ctx)` để gửi thử trong test mà không cần SMTP.

## Bám theo Laravel trong lúc làm v2

Laravel vẫn chạy production và được sửa bình thường. `doi-chieu-laravel.txt` ghi mỗi mảng v2 dựa trên file Laravel nào, đã đối chiếu tới commit nào.

- Workflow *Đối chiếu v2 với Laravel* chạy trên mọi PR: PR sửa file Laravel của mảng đã chuyển thì hiện cảnh báo "Cần cập nhật v2" (không chặn merge).
- Xem mảng nào đang lệch: `v2/tools/parity.sh`, rồi `git diff <commit> HEAD -- <file>` để biết cần bổ sung gì. Cập nhật v2 xong thì sửa commit trong `doi-chieu-laravel.txt`.
- Dữ liệu không đối chiếu tay: `menglish import-legacy` (bước 4) đọc thẳng DB Laravel, chạy lại được nhiều lần; trước khi chuyển hẳn một mảng thì khóa mảng đó trên Laravel và import lần cuối.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## MEnglish — Dữ liệu demo (Phase 1)

Seed đầy đủ (dữ liệu hệ thống + dữ liệu demo luồng khách → test → học thử → chốt → vào lớp / lớp chờ):

```bash
php artisan migrate:fresh --seed                         # xóa sạch DB rồi seed lại
php artisan db:seed                                      # seed thêm vào DB hiện có (chạy lại không nhân bản)
php artisan db:seed --class=DemoPhase1Seeder             # chỉ dữ liệu demo Phase 1
SEED_DEMO=true php artisan db:seed                       # bật demo ở môi trường khác local/testing/staging
DB_CONNECTION=sqlite DB_DATABASE=/tmp/demo.sqlite php artisan migrate:fresh --seed   # thử nhanh trên SQLite (tạo file trống trước)
```

- `DemoPhase1Seeder` chỉ chạy khi `APP_ENV` là `local`, `testing`, `staging` hoặc có `SEED_DEMO=true`. **Không bật trên production.**
- Dữ liệu cho 2 chi nhánh **Cầu Giấy (CG)** và **Ba Đình (BD)**: mỗi chi nhánh 14 khách đủ 8 bước pipeline + Thất bại (có lý do), hạn liên hệ quá hạn / sắp hết hạn, bài test theo khối lớp (đã chấm + chờ chấm), học thử (đã hẹn + đã học có nhận xét GV), 3 lớp mẫu (`DEMO-<CN>-FAM1` đang học còn chỗ, `DEMO-<CN>-FAM2` sắp khai giảng chưa đủ ngưỡng, `DEMO-<CN>-FAM0` đã đầy) với buổi học sinh sẵn, bỏ ngày nghỉ `HOL-DEMO-<CN>`; khách đã chốt có hồ sơ học viên, tài khoản, học phí (đã duyệt / chưa đóng → task "Nhắc thu học phí"), khách Chờ xếp lớp và học viên chờ Xác nhận chính thức. Khách demo có SĐT bắt đầu `039`, tên có tiền tố `# `.

Tài khoản demo (mật khẩu chung = `SEED_DEFAULT_PASSWORD` trong `.env`, mặc định `Password123!` — `config('access.seed_password')`):

| Vai trò | Email | Chi nhánh |
|---|---|---|
| Admin | `admin@menglish.edu.vn` | CG |
| Quản lý cơ sở (CM) | `manager@menglish.edu.vn` / `manager.bd@menglish.edu.vn` | CG / BD |
| Học vụ (CM, chấm test, gán lớp) | `nva@menglish.edu.vn` / `giaovu2@menglish.edu.vn` | CG / BD |
| Học thuật | `academiclead@menglish.edu.vn` | CG |
| Sale | `tranmaia@menglish.edu.vn` / `hoangthinh@menglish.edu.vn` / `levanvu@menglish.edu.vn` | CG / BD / DD |
| Kế toán | `ketoan2@menglish.edu.vn` / `ttb@menglish.edu.vn` | CG / BD |
| Giáo viên (nhận xét học thử) | `nguyenvanan@menglish.edu.vn` (CG, lớp FAM1/FAM0) · `gv.cohuu1@menglish.edu.vn` (CG, FAM2) · `gv.cohuu2@menglish.edu.vn` (BD) | |
| Trợ giảng | `ta.tuan@menglish.edu.vn` / `ta.yen@menglish.edu.vn` | CG / BD |

Học viên tạo khi chốt khách nhận mật khẩu ngẫu nhiên (bắt buộc đổi khi đăng nhập). Kiểm thử nghiệm thu Phase 1: `php artisan test --filter=Phase1AcceptanceTest`.

## MEnglish — Dữ liệu demo (Phase 2: vận hành lớp học)

`DemoPhase2Seeder` chạy ngay sau `DemoPhase1Seeder` (cùng điều kiện môi trường, gọi từ `DatabaseSeeder`) và dựng trên lớp / học viên của Phase 1. Chạy riêng: `php artisan db:seed --class=DemoPhase2Seeder` (cần dữ liệu Phase 1 trước). Idempotent, ~1,5 giây. Seeder **luôn ép Zalo ZNS về chế độ sandbox** (chỉ ghi log, không gửi tin thật) kể cả khi `.env` có `ZALO_ACCESS_TOKEN`.

Có gì trong dữ liệu:
- **Giáo trình → Chặng → Unit → Buổi**: `DEMO-SYL-STARTERS` (3 chặng, 18 buổi, gắn trình độ `DEMO-STARTERS` → lớp FAM 0 / FAM 1) và `DEMO-SYL-MOVERS` (2 chặng, gắn `DEMO-MOVERS` → FAM 2).
- **Chặng của lớp** (mỗi lớp 1 chặng mở): `DEMO-CG-FAM1` đã **đóng Chặng 1** nhờ Big Test đã duyệt và gửi PH (có 1 HV vắng thi) và **tự mở Chặng 2**, kèm order đề Chặng 2 **chờ duyệt**; `DEMO-CG-FAM0` Big Test **đã duyệt, chưa gửi PH**; `DEMO-BD-FAM1` Big Test GV vừa nhập điểm, **chờ Học thuật duyệt**; `DEMO-BD-FAM0` order đã duyệt kèm link đề, **đợt thi trong 5 ngày tới** (đã nhắc lịch 7 ngày) + 1 order mini test **bị từ chối**. Lớp FAM 2 (sắp khai giảng) chưa giao chặng — dùng để thử màn Giao chặng.
- **Giảng dạy**: điểm danh theo buổi (6 buổi gần nhất; buổi gần nhất để trống để thử **điểm danh bù**; 1 buổi Học vụ điểm danh thay), vắng / vắng có phép → **danh sách bổ trợ**; nhận xét buổi học; mini test (điểm < 7 → bổ trợ); 2 bài tập + bài nộp (1 bài đã chấm); mỗi lớp 2 **buổi bổ trợ** (1 đã dạy xong, 1 ngày mai).
- **Lịch**: GVNN `gv.native1@…` trên các buổi sắp tới của `DEMO-CG-FAM1`; ngày nghỉ thêm sau `HOL-DEMO2-<CN>` hủy 1 buổi FAM 1 và tự xếp **buổi học bù** cuối lịch.
- **Việc**: việc trực ca trợ giảng hôm qua + hôm nay (1 việc hoàn thành có ảnh, 1 việc chờ xác nhận); việc **chăm sóc tháng đầu** (mốc ngày 3/7/14/30) cho Học vụ; nhắc **sinh nhật** (HV `HV-DEMO-<CN>-02` sinh nhật hôm nay).

Đăng nhập để xem (mật khẩu như trên):

| Vai trò | Email | Xem gì |
|---|---|---|
| Giáo viên | `nguyenvanan@menglish.edu.vn` (CG: FAM 1, FAM 0) · `gv.cohuu2@menglish.edu.vn` (BD) | Lịch dạy hôm nay / tuần, điểm danh từng buổi, nhận xét, mini test, order đề, nhập kết quả Big Test, Xem giáo trình |
| GVNN | `gv.native1@menglish.edu.vn` | Lịch dạy các buổi sắp tới của `DEMO-CG-FAM1` |
| Trợ giảng | `ta.tuan@menglish.edu.vn` (CG) · `ta.yen@menglish.edu.vn` (BD) | Nhiệm vụ hôm nay (`/portal/ta-tasks`), buổi bổ trợ được xếp |
| Học vụ | `nva@menglish.edu.vn` / `giaovu2@menglish.edu.vn` | Danh sách bổ trợ + xếp buổi, dashboard lớp, điểm danh thay, việc chăm sóc tháng đầu |
| Học thuật | `academiclead@menglish.edu.vn` | Soạn syllabus, Giao chặng, Duyệt & phân phối đề, Duyệt kết quả & gửi PH |
| Học viên | `hocvien1@menglish.edu.vn` (`HV-DEMO-CG-01`, FAM 1) · `hocvien2@menglish.edu.vn` (`HV-DEMO-CG-08`, FAM 0) · `hocvien3@menglish.edu.vn` (`HV-DEMO-BD-01`) | Cổng học viên: lịch học, điểm danh, kết quả Big Test, nhận xét, bài tập |

Kiểm thử nghiệm thu Phase 2: `php artisan test --filter='Phase2AcceptanceTest|DemoPhase2SeederTest'`.

## MEnglish — Dữ liệu demo (Phase 3: chấm công → lương)

`DemoPhase3Seeder` chạy sau `DemoPhase2Seeder` (cùng điều kiện môi trường, gọi từ `DatabaseSeeder`), dựng trên buổi học / học viên / tài khoản của Phase 1–2. Chạy riêng: `php artisan db:seed --class=DemoPhase3Seeder` (cần Phase 1–2 trước). Idempotent (đánh dấu `[demo-p3]` trên đơn giá GV — đã có thì chỉ in số liệu), ~2,5 giây, toàn bộ trong 1 transaction. Các sự kiện được chạy **đúng thời điểm trong quá khứ** (check-in lúc vào ca, Kế toán tính kỳ tháng trước ngày 1, Admin duyệt ngày 2…) nên hạn nộp phạt, gate 30 ngày, khóa kỳ chạy như thật. Seeder in bảng số dòng và **thực lĩnh từng người** của 2 kỳ.

Có gì trong dữ liệu (L = tháng trước, C = tháng này):
- **Hồ sơ lương**: lương cơ bản cho GV full-time (`gv.cohuu1`, `gv.cohuu2`), Học vụ, Học thuật, Sale; `nguyenvanan` hợp đồng Bán thời gian (GV part-time).
- **Đơn giá buổi riêng** (màn Đơn giá GV): `nguyenvanan` 220.000đ/buổi từ tháng L−1, **phiên bản mới** 250.000đ/buổi từ ngày 1 tháng C; TA 120.000đ/buổi; GVNN 450.000đ/buổi. Mốc hoa hồng mặc định 3% / 4% / 5% theo số HS chốt; bảng % thưởng tái tục (0 nghỉ → 1%, 1 nghỉ → 0,7%, còn lại chờ BA).
- **Chấm công** (màn Chấm công GV): GV / TA **tự check-in đúng ngày** mọi buổi thật đã qua của `DEMO-*-FAM1` / `FAM0`; 1 buổi GV quên check-in → Học vụ **chấm công tay** (lý do + giờ vào/ra), lần nhập trùng **bị từ chối**; 1 ca chấm tay không có buổi trên lịch (workshop) và ca mẫu không gắn buổi của MasterEntitySeeder **bị từ chối khi duyệt**; Học vụ duyệt ca mỗi sáng.
- **Kỷ luật** (màn Danh sách vi phạm): đủ các bước chờ giải trình / đã giải trình / xác nhận lỗi / quyết phạt (chưa tới hạn) / đã nộp trực tiếp / **đã trừ lương** (quá hạn, kỳ L) / đã hủy; 1 biên bản quá hạn trừ ở kỳ C.
- **KPI Học vụ** 15 mục / 6 nhóm tháng L và C cho `nva`, `giaovu2` (Quản lý chi nhánh chấm).
- **Hoa hồng** (Sale `tranmaia`): 4 khách mới SĐT `0377…` chốt vào `DEMO-CG-FAM1`. Khách chốt tháng L−1, đủ 3/3 mốc → **trả ở kỳ L**; 3 khách chốt đầu tháng L → **hoãn** ở kỳ L (chưa đủ 30 ngày); tháng C 2 khách đủ 3/3 mốc → **trả kỳ C**, 1 khách 2/3 mốc → vẫn hoãn. Khách đã được trả hoa hồng **hoàn phí**, Admin chọn **thu hồi** → trừ ở kỳ C.
- **Kỳ lương**: kỳ L **Đã duyệt** (khóa; Kế toán đã nhập bậc KPI giữ HS, KPI tự do, thuế TNCN, phụ cấp / khấu trừ tự do rồi tính lại); kỳ C **Đang soát** (đã tính + nhập tay, chờ Admin duyệt).

Đăng nhập để xem (mật khẩu như trên):

| Vai trò | Email | Xem gì |
|---|---|---|
| Kế toán | `ketoan2@menglish.edu.vn` | Lương → Danh sách bảng lương: kỳ C "Đang soát" → phiếu lương từng người (nhập bậc KPI / TNCN / dòng tự do, "Đồng bộ & Tính lại"); không có nút duyệt |
| Admin | `admin@menglish.edu.vn` | Duyệt kỳ C; kỳ L đã khóa; Đơn giá GV, Mốc hoa hồng, Tham số tính lương, Danh sách vi phạm |
| Giáo viên part-time | `nguyenvanan@menglish.edu.vn` | "Lương của tôi": chỉ thấy kỳ L (đã duyệt), kỳ C chưa hiện; biên bản của mình để giải trình |
| GV full-time / TA | `gv.cohuu2@menglish.edu.vn` · `ta.tuan@menglish.edu.vn` | "Lương của tôi" kỳ L (BHXH 10,5% + Công đoàn 0,5% cho full-time; part-time không trừ) |
| Học vụ | `nva@menglish.edu.vn` | Chấm công tay, duyệt chấm công, ghi nhận vi phạm, đánh giá KPI (không tự chấm mình) |
| Quản lý cơ sở | `manager@menglish.edu.vn` | Chốt lỗi vận hành + quyết phạt, ghi nhận nộp phạt, tick mốc chăm sóc tháng đầu, đơn giá GV |
| Học thuật | `academiclead@menglish.edu.vn` | Chốt lỗi chuyên môn + quyết phạt |
| Sale | `tranmaia@menglish.edu.vn` | "Lương của tôi" kỳ L: hoa hồng trả / hoãn |

Kiểm thử nghiệm thu Phase 3: `php artisan test --filter='Phase3AcceptanceTest|DemoPhase3SeederTest'`.

## MEnglish — Dữ liệu demo (Phase 4: học phí, hỗ trợ, vận hành)

`DemoPhase4Seeder` chạy sau `DemoPhase3Seeder` (cùng điều kiện môi trường, gọi từ `DatabaseSeeder`). Chạy riêng: `php artisan db:seed --class=DemoPhase4Seeder` (cần Phase 1–3 trước). Idempotent (đã có khách `0388000001` thì chỉ in số liệu), ~3 giây, 1 transaction; mọi thao tác đi qua controller thật với "đồng hồ" đặt đúng thời điểm trong quá khứ (khách chốt N ngày trước → hạn đóng N−7 ngày trước), nên nhóm quá hạn, doanh thu tháng trước / tháng này hình thành như thật. Seeder in bảng số dòng và các bước bị chặn / bỏ qua đúng quy tắc.

Có gì trong dữ liệu:
- **Cấu hình**: tài khoản ngân hàng riêng Cầu Giấy (MB) / Ba Đình (Techcombank) → QR theo chi nhánh; **dải số HĐ riêng** `C26MCG` / `C26MBD` (1–500); SePay xác thực HMAC-SHA256 với **khóa sinh ngẫu nhiên mỗi lần seed** (muốn thử webhook bằng tay: đặt `SEPAY_WEBHOOK_ENABLED=true` rồi nhập khóa mới ở Cấu hình → Tài khoản ngân hàng → SePay); mốc nhắc nợ thêm **T+7**, mốc "quá hạn bắt buộc liên hệ" = 7 ngày.
- **9 khách học phí** (SĐT `0388…`, tên `# …`, Sale nhập → Học vụ chuyển Đang tư vấn → Quản lý Chốt & xếp lớp sau, khóa FAM 2):
  - `# Đinh Khánh Linh` (CG): đợt 1 Học vụ lập **nháp** → gửi duyệt (CK + minh chứng) → Kế toán **trả về** (sai mã GD) → sửa, gửi lại → Kế toán duyệt (HĐ `C26MCG-…`); đợt 2 PH chuyển khoản đúng nội dung QR → **SePay tự gạch nợ** + xuất HĐ → **công nợ 0**; webhook gửi lại bị bỏ qua.
  - `# Hồ Minh Châu` (CG): **quá hạn ≥ 7 ngày**, đã gửi nhắc, **Đã liên hệ** 2 lần, **Báo cáo Admin**; hôm nay PH báo đã CK → phiếu **chờ duyệt**.
  - `# Quách Thu Trang` (BD): đóng 1 phần tiền mặt → **quá hạn 1–6 ngày**; đợt 2 phiếu tay CK rồi SePay cùng mã đến sau → `duplicate_manual` (không ghi 2 lần), Kế toán vẫn duyệt phiếu tay; **yêu cầu hủy HĐ đợt 1 chờ duyệt**.
  - `# Lý Hoàng Nam` (BD): **khất nợ** (hạn mới +10 ngày, tạm dừng nhắc nợ). `# Mai Quốc Việt` (BD): đóng 50% → **bảo lưu** 30 ngày (học viên "Bảo lưu", đóng băng công nợ).
  - `# Tăng Bảo Ngọc` (CG): đóng đủ khi chốt → **chuyển nhượng** 3.000.000đ sang em `# Tăng Bảo Anh` (Admin duyệt) → hồ sơ **hoàn phí chờ duyệt đã quá 1 tuần**.
  - `# Trịnh Đức Anh` (CG): sắp đến hạn, 1 phiếu **nháp** + 1 phiếu CK **bị trả về** chưa sửa. `# Viên Thảo Nhi` (BD): đóng đủ → **hủy hóa đơn** (Kế toán yêu cầu, Quản lý duyệt) → công nợ khôi phục.
  - SePay: thêm 1 giao dịch vào **tài khoản lạ** (không gạch nợ) và 1 giao dịch **không nhận ra học viên** (chờ đối soát tay).
- **Thu chi**: 9 khoản chi vận hành tháng trước + tháng này cho CG / BD (mặt bằng, internet, in ấn…); doanh thu từ phiếu đã duyệt → màn Báo cáo doanh thu tạm tính.
- **Vận hành**: giao việc **2 chiều** (Admin → GV; GV → Học vụ, Học vụ gửi chờ xác nhận, GV duyệt; TA → Quản lý); **trợ giảng 3 ca** hôm nay cho `ta.yen` (trước giờ có ảnh → hoàn thành, trong giờ không ảnh → chờ xác nhận); **báo cáo trực lớp** có ảnh bảng (`ta.tuan`, tự hoàn thành) và không ảnh (`ta.yen`, chờ GV chính `gv.cohuu2` xác nhận); 2 **ticket** (GV báo hỏng máy chiếu; học viên xin hóa đơn) có **ghi chú nội bộ** ẩn với người tạo.
- **Tài khoản & phân quyền**: tài khoản mới `ketoan.moi@menglish.edu.vn` (**bắt đổi mật khẩu** lần đầu); `gv.cohuu1` **hợp đồng hết hạn sau 20 ngày** (nhãn "HĐ sắp hết hạn", thông báo Admin + Quản lý CG; nhật ký có trước / sau); **phân quyền cá nhân**: Sale CG xem lớp **chi nhánh CG**, Sale BD xem + sửa đúng **lớp `DEMO-BD-FAM1`**.

Kiểm thử nghiệm thu Phase 4: `php artisan test --filter='Phase4AcceptanceTest|DemoPhase4SeederTest|FullBpmnSmokeTest'`.

## Kiểm tra nhanh toàn hệ thống

```bash
cp .env.example .env && php artisan key:generate                   # lần đầu (APP_ENV=local để bật dữ liệu demo)
touch /tmp/menglish-demo.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/menglish-demo.sqlite php artisan migrate:fresh --seed   # ~15 giây: hệ thống + demo Phase 1–4
php artisan test --filter='Phase[1-4]AcceptanceTest|DemoPhase[1-4]SeederTest|FullBpmnSmokeTest'  # nghiệm thu từng phase + lượt BPMN 1–22
```

Mật khẩu chung các tài khoản demo = `SEED_DEFAULT_PASSWORD` (mặc định `Password123!`). Đi theo thứ tự BPMN:

| Bước | Luồng | Tài khoản | Màn hình / thao tác |
|---|---|---|---|
| 1–4 | Khách → test → học thử → chốt | Sale `tranmaia@…`, Học vụ `nva@…`, Quản lý `manager@…`, GV `nguyenvanan@…` | CRM → Pipeline (Sale nhập, Học vụ chuyển bước), Test đầu vào (Học vụ chấm), Học thử (GV nhận xét), Chốt & Xếp lớp / Chờ xếp lớp / Xác nhận chính thức |
| 5–8, 21 | Lớp, lịch, giáo trình, dạy & điểm danh, chăm sóc | Học vụ `nva@…`, Học thuật `academiclead@…`, GV `nguyenvanan@…` | Dashboard lớp, TKB, Giao chặng, Điểm danh theo buổi, Bổ trợ, việc chăm sóc tháng đầu |
| 10–14 | Big Test → PH nhận kết quả; cổng học viên | Học thuật `academiclead@…`, học viên `hocvien1@…` | Duyệt kết quả & gửi PH; Cổng học viên: lịch, điểm danh, kết quả |
| 9, 9b, 16, 17 | Chấm công → phạt / hoa hồng / KPI → lương | Học vụ `nva@…`, Kế toán `ketoan2@…`, Admin `admin@…`, GV `nguyenvanan@…` | Chấm công GV, Danh sách vi phạm, Bảng lương (kỳ tháng này Đang soát → Admin duyệt), Lương của tôi |
| 15 | Phiếu thu → duyệt → HĐ → công nợ | Học vụ `nva@…` (lập), Kế toán `ketoan2@…` (duyệt, CG) / `ttb@…` (BD) | Học phí → Lập phiếu thu / Duyệt phiếu thu / Lịch sử thu; khách `# Đinh Khánh Linh` (nợ 0), `# Hồ Minh Châu` (chờ duyệt) |
| 15b | Hủy HĐ, hoàn phí, chuyển nhượng, bảo lưu, khất nợ, quá hạn | Kế toán `ketoan2@…` / `ttb@…`, Quản lý `manager.bd@…`, Admin | Duyệt hủy HĐ, Hoàn tiền & khất nợ, Thu phí quá hạn (nhóm ≥ 7 / 1–6 ngày, Đã liên hệ, Báo cáo Admin), Cấu hình nhắc nợ, Dải số HĐ, Tài khoản NH |
| 18–20 | Giao việc, trợ giảng 3 ca, trực lớp, ticket, thu chi | Admin, Quản lý `manager@…`, TA `ta.tuan@…` / `ta.yen@…`, GV `gv.cohuu2@…`, Kế toán `ketoan2@…` | Phân công công việc, Nhiệm vụ hôm nay (TA), Xác nhận hoàn thành (GV chính duyệt báo cáo không ảnh), Ticket, Báo cáo doanh thu tạm tính, Khoản chi |
| 22 | Dashboard, tài khoản, phân quyền, nhật ký | Admin, Quản lý, Học thuật; `ketoan.moi@…` (bắt đổi mật khẩu) | Dashboard theo vai trò, Tài khoản (HĐ sắp hết hạn `gv.cohuu1`), Phân quyền cá nhân (`tranmaia`, `hoangthinh`), Nhật ký vận hành (so sánh trước / sau) |

Quản lý / Kế toán có gán chi nhánh chỉ thấy học phí và báo cáo thu chi của chi nhánh mình (thử `manager.bd@…` / `ttb@…`); Sale không vào được Học phí / Thu chi.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


## Deploy lên hosting DirectAdmin

Xem [docs/deploy-directadmin.md](docs/deploy-directadmin.md): deploy bằng GitHub Actions (workflow *Deploy hosting (DirectAdmin)*) qua FTP, không cần SSH.

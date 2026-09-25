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

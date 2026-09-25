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

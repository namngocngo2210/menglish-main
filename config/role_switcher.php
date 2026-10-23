<?php

/*
|--------------------------------------------------------------------------
| Đổi vai trò trên bản thử nghiệm (dungthu.meducation.vn)
|--------------------------------------------------------------------------
|
| Người test nhập một mã truy cập chung ở trang đăng nhập (mỗi trình duyệt một lần), sau đó chọn vai trò / người để
| vào thẳng màn hình của tài khoản đó, không cần mật khẩu; đổi tiếp bằng nút "Đổi vai trò" trên topbar.
| Xem App\Support\RoleSwitcher.
|
| Chỉ bật khi có cả ROLE_SWITCHER_ENABLED=true và ROLE_SWITCHER_CODE. Luôn tắt khi APP_ENV=production, kể cả khi
| bật nhầm. Đổi ROLE_SWITCHER_CODE → mọi trình duyệt đã nhập mã cũ mất quyền ngay.
|
*/

return [

    'enabled' => (bool) env('ROLE_SWITCHER_ENABLED', false),

    'code' => (string) env('ROLE_SWITCHER_CODE', ''),

];

<?php

/*
|--------------------------------------------------------------------------
| Đổi vai trò nhanh (chỉ môi trường thử nghiệm, vd. dungthu.meducation.vn)
|--------------------------------------------------------------------------
|
| Super Admin đăng nhập một lần rồi chuyển qua lại giữa các tài khoản mẫu theo vai trò (nút "Đổi vai trò" trên
| topbar) mà không phải đăng xuất / gõ mật khẩu từng tài khoản. Xem App\Support\RoleSwitcher.
|
| Tắt mặc định. Bật bằng ROLE_SWITCHER_ENABLED=true trong .env của staging. Luôn tắt khi APP_ENV=production,
| kể cả khi biến này bật nhầm.
|
*/

return [

    'enabled' => (bool) env('ROLE_SWITCHER_ENABLED', false),

    /*
    | Tài khoản mẫu (DemoPayrollSeeder, DemoPhase*Seeder…) hiện trong menu, theo thứ tự này trong từng vai trò:
    | email => ghi chú ngắn cho người test. Vai trò không có tài khoản nào ở đây (hoặc chưa seed) thì menu lấy
    | vài tài khoản đang hoạt động đầu tiên của vai trò đó.
    */
    'accounts' => [
        'admin@menglish.edu.vn' => 'Toàn quyền, duyệt kỳ lương',
        'manager@menglish.edu.vn' => 'KPI tốt',
        'ttb@menglish.edu.vn' => 'KPI xấu (~22%)',
        'manager.bd@menglish.edu.vn' => null,
        'ketoan2@menglish.edu.vn' => null,
        'ketoan.moi@menglish.edu.vn' => 'Bắt đổi mật khẩu lần đầu',
        'academiclead@menglish.edu.vn' => 'Duyệt lỗi học thuật, KPI học thuật',
        'nva@menglish.edu.vn' => 'KPI tốt, duyệt công CG',
        'giaovu2@menglish.edu.vn' => 'KPI xấu, duyệt công BD',
        'giaovu.dd@menglish.edu.vn' => null,
        'levanvu@menglish.edu.vn' => 'KPI tốt',
        'hoangthinh@menglish.edu.vn' => 'KPI xấu',
        'tranmaia@menglish.edu.vn' => null,
        'sale.khanhlinh@menglish.edu.vn' => null,
        'gv.cohuu2@menglish.edu.vn' => 'KPI tốt',
        'gv.cohuu1@menglish.edu.vn' => 'KPI xấu, đi muộn 18 phút',
        'gv.hamy@menglish.edu.vn' => null,
        'gv.banthoigian1@menglish.edu.vn' => 'KPI tốt; muộn 8/12 phút, quên chấm công',
        'nguyenvanan@menglish.edu.vn' => 'KPI xấu',
        'gv.native1@menglish.edu.vn' => 'GVNN, muộn 22 phút (hủy buổi)',
        'gv.minhduc@menglish.edu.vn' => null,
        'gv.thaonguyen@menglish.edu.vn' => null,
        'ta.hai@menglish.edu.vn' => 'KPI tốt; vắng 1 buổi',
        'ta.linh@menglish.edu.vn' => 'KPI xấu',
        'ta.thu@menglish.edu.vn' => 'Có phiếu công workshop bị từ chối',
        'ta.tram@menglish.edu.vn' => 'Muộn 6 phút',
        'ta.tuan@menglish.edu.vn' => null,
        'ta.yen@menglish.edu.vn' => null,
        'ta.thuydung@menglish.edu.vn' => null,
        'ta.giabao@menglish.edu.vn' => null,
        'hocvien1@menglish.edu.vn' => null,
        'hocvien2@menglish.edu.vn' => null,
        'hocvien3@menglish.edu.vn' => null,
    ],

    /** Số tài khoản lấy thêm cho vai trò không có tài khoản mẫu nào ở trên. */
    'fallback_per_role' => 3,

];

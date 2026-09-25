<?php

/*
|--------------------------------------------------------------------------
| Công thức lương theo BA (A6 — 25/09/2026, Q3)
|--------------------------------------------------------------------------
|
| Giá trị mặc định cho PayrollPeriod::calculatePayrollForPeriod(). Các tỉ lệ có thể được
| Admin ghi đè ở màn "Tham số tính lương" (SystemSetting, tiền tố payroll_); khi chưa
| cấu hình thì dùng giá trị ở đây.
|
*/

return [

    // ── Full-time: khấu trừ tự động trên LƯƠNG CƠ BẢN ────────────────────────────
    'insurance_rate_percent' => 10.5,   // BHXH (phần người lao động)
    'union_rate_percent' => 0.5,        // Công đoàn
    // Thuế TNCN: Admin / Kế toán nhập tay trên phiếu lương (không tự tính).

    // ── Part-time: KPI giữ học sinh ──────────────────────────────────────────────
    // Số HS giữ được × bậc (đ / HS / tháng). Admin chọn bậc bằng tay cho từng GV từng kỳ.
    'retention_tiers' => [15000, 20000, 25000],

    /*
     * Định nghĩa "số HS giữ được" (chờ BA xác nhận):
     * - Lớp tính: lớp GV là GV chính (classes.teacher_id) — 'main_teacher'; hoặc thêm cả lớp GV có ca dạy
     *   hợp lệ trong kỳ — 'main_teacher_or_taught'.
     * - Mẫu số: học viên đang có lượt xếp lớp hiệu lực trong lớp TẠI NGÀY ĐẦU KỲ.
     * - Giữ được: trong số đó, học viên KHÔNG chuyển sang các trạng thái ở 'lost_statuses' (mặc định chỉ
     *   Thôi học) tính tới NGÀY CUỐI KỲ. Bảo lưu / Nghỉ hè vẫn tính là giữ được.
     */
    'retention' => [
        'class_scope' => 'main_teacher_or_taught',
        'lost_statuses' => ['dropped'],
    ],

    // ── Học vụ: KPI 6 nhóm / 15 mục ─────────────────────────────────────────────
    // Tiền KPI = quỹ × tổng điểm có trọng số (%) của đánh giá KPI tháng đã chốt.
    'academic_kpi_fund' => 2000000,

    // ── Hoa hồng tuyển sinh (khách mới) ─────────────────────────────────────────
    'commission' => [
        // Gate kép: đủ N ngày từ ngày chốt VÀ đủ số mốc chăm sóc tháng đầu; thiếu → hoãn sang kỳ sau.
        'gate_days' => 30,
        'gate_milestones' => 3,
        // Bậc mặc định theo số HS chốt trong kỳ (Admin sửa ở màn Mốc hoa hồng). Ngưỡng số HS chờ BA chốt.
        'default_tiers' => [
            ['tier_name' => 'Bậc 1 (0–5 HS chốt)', 'min_students' => 0, 'max_students' => 5, 'new_sale_percent' => 3],
            ['tier_name' => 'Bậc 2 (6–10 HS chốt)', 'min_students' => 6, 'max_students' => 10, 'new_sale_percent' => 4],
            ['tier_name' => 'Bậc 3 (từ 11 HS chốt)', 'min_students' => 11, 'max_students' => null, 'new_sale_percent' => 5],
        ],
    ],

    // ── Thưởng tái tục (GV phụ trách lớp) ────────────────────────────────────────
    // % × doanh thu lớp trong kỳ, % tra theo SỐ HS NGHỈ (Thôi học) trong kỳ của lớp.
    // BA mới chốt 2 mốc: giữ đủ 100% → 1%, nghỉ 1 HS → 0,7%. Các mốc còn lại CHỜ BA: để 0%.
    'renewal_bonus' => [
        'table' => [
            0 => ['percent' => 1.0, 'pending' => false],
            1 => ['percent' => 0.7, 'pending' => false],
            2 => ['percent' => 0.0, 'pending' => true],
            3 => ['percent' => 0.0, 'pending' => true],
        ],
        // Nghỉ nhiều hơn mốc lớn nhất trong bảng → % này (chờ BA).
        'beyond_percent' => 0.0,
    ],

];

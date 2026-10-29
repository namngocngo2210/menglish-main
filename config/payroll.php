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

    // ── BHXH / Công đoàn trên MỨC ĐÓNG BHXH (TK-0009, 30/10/2026) ──────────────
    // BHXH = mức đóng × 10,5%, Công đoàn = mức đóng × 0,5% (đủ tháng: 567.000 / 27.000). Mức đóng không cộng vào thu nhập.
    // Tháng làm không đủ → × công hưởng lương / công chuẩn. Chỉ áp cho các vai trò lương dưới đây (Full-time, lương cơ bản > 0);
    // Sale, Quản lý, nhân sự khác, Part-time, Trợ giảng → 0. Mức đóng sửa ở màn "Tham số tính lương".
    'insurance_salary_base' => 5400000,
    'insurance_rate_percent' => 10.5,   // BHXH (phần người lao động)
    'union_rate_percent' => 0.5,        // Công đoàn
    'insurance_roles' => ['teacher_fulltime', 'academic_staff', 'academic_lead'],
    // Thuế TNCN: Admin / Kế toán nhập tay trên phiếu lương (không tự tính).

    // ── Full-time: ngày công (TK-0012) ───────────────────────────────────────────
    // Lương cơ bản / phụ cấp hợp đồng thực trả = mức hợp đồng ÷ công chuẩn × công hưởng lương (tối đa đủ tháng).
    // - Công chuẩn = số ngày trong tháng − ngày nghỉ cố định trong tuần của nhân sự (nghỉ 1 ngày/tuần hoặc T7 + CN).
    //   Ngày lễ VẪN tính trong công chuẩn.
    // - Công hưởng lương = ngày chấm công (đủ vào + ra hoặc đã chấm công bù = 1 công; có vào, quên ra, không bù = 0,5 công)
    //   + ngày nghỉ phép đã duyệt + ngày lễ rơi vào ngày làm việc (paid_holidays). Hợp đồng bắt đầu giữa tháng: ngày
    //   trước ngày bắt đầu không được trả.
    'workday' => [
        // Nhân sự chưa đặt lịch nghỉ trong tuần (hồ sơ → Hợp đồng & Lương) tính công chuẩn như nghỉ Chủ nhật (1 = T2 … 7 = CN).
        'default_weekly_days_off' => [7],
        'missing_checkout_credit' => 0.5,
        // Chủ dự án chốt 30/10/2026: ngày lễ rơi vào ngày làm việc được trả lương. Đổi tại đây nếu chính sách thay đổi.
        'paid_holidays' => true,
    ],

    // ── Full-time: đi muộn / quên chấm công (TK-0013) ────────────────────────────
    // Đi muộn: cuối tháng cộng dồn tổng phút muộn không có phép ÷ 60 × công giờ; công giờ = công ngày ÷ 7, công ngày =
    // lương cơ bản ÷ công chuẩn. Không lập biên bản từng lần. GV / TA: đi muộn tính theo check-in ca dạy (không trừ ở đây).
    // Quên chấm vào / chấm ra: phạt mỗi lần (sửa ở Tham số tính lương), kể cả ngày đã được chấm công bù.
    'attendance' => [
        'hours_per_day' => 7,
        'missing_punch_fine' => 30000,
    ],

    // ── Trợ giảng (TK-0010, TK-0011) ─────────────────────────────────────────────
    // Lương TA = số giờ dạy hợp lệ × đơn giá giờ. Bậc đơn giá (đ/giờ) Admin chọn cho từng TA ở màn Đơn giá giáo viên.
    // Đơn giá TA cũ theo buổi không quy đổi: TA chưa có đơn giá giờ → lương giờ 0đ + cảnh báo cần nhập lại.
    // TA không có KPI, không phụ cấp, không thưởng tái tục; vẫn bị trừ phạt.
    'assistant_hourly_tiers' => [30000, 35000, 40000],

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

    // ── GV Part-time: đi muộn / về sớm ───────────────────────────────────────────
    // Có báo trước → trả theo số phút thực dạy. Không báo trước: dưới ngưỡng → trừ mỗi phút (Admin chỉnh
    // 4.000–5.000đ ở màn Tham số tính lương); từ ngưỡng trở lên → không tính buổi.
    'late' => [
        'threshold_minutes' => 15,
        'deduction_per_minute' => 5000,
        'deduction_per_minute_min' => 4000,
        'deduction_per_minute_max' => 5000,
    ],

    // ── Học vụ: KPI 6 nhóm / 15 mục ─────────────────────────────────────────────
    // Tiền KPI = quỹ × tổng điểm có trọng số (%) của đánh giá KPI tháng đã chốt.
    'academic_kpi_fund' => 2000000,

    // ── Học thuật kiêm nhiệm giảng dạy ──────────────────────────────────────────
    // Lương đứng lớp = Σ buổi dạy hợp lệ × (học phí theo buổi của từng HS trong lớp) × %. % riêng từng người ở màn
    // Đơn giá giáo viên (đơn vị "% học phí"); chưa có thì lấy mức mặc định này (sửa ở Tham số tính lương).
    'teaching_share_percent' => 40,

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

    // ── Thưởng tái tục: chỉ Học vụ (academic_staff, TK-0015) ─────────────────────────
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

<?php

namespace Database\Seeders;

use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\TeacherPortalController;
use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CommissionAdjustment;
use App\Models\CommissionItem;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\KpiEvaluationItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Roles;
use Closure;
use Database\Seeders\Concerns\InvokesControllersAsUser;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dữ liệu demo phần lương (Phase 5): chấm công điện thoại, đơn xin duyệt, giờ dạy, KPI mọi vai trò, bảng lương kỳ đang soát.
 * Dựng trên tài khoản UserSeeder và dữ liệu DemoPhase1–4 (chạy sau DemoPhase4Seeder), chỉ THÊM dữ liệu, không sửa / xóa
 * bản ghi đã có (trừ điền hồ sơ lương còn trống và toạ độ chấm công của cơ sở chưa cài).
 *
 * Mốc thời gian tương đối với hôm nay: L = tháng trước (kỳ lương đã chốt, khóa), C = tháng này (kỳ đang soát).
 * - Cài chấm công cho cơ sở Cầu Giấy / Ba Đình / Đống Đa chưa có toạ độ (bán kính 150 m, giờ làm 08:00–17:30, cho muộn 10').
 * - 7 nhân sự mới vào làm từ đầu tháng này (GV part-time / full-time, trợ giảng, Học vụ Đống Đa, Sale), điền hồ sơ lương còn
 *   trống của nhân sự cũ (Quản lý cơ sở, Sale, loại hợp đồng GV / TA), đơn giá buổi riêng cho GV / TA chưa có.
 * - 5 lớp khai giảng K28 đầu tháng này (Ca 1 18:00 / Ca 2 19:30 ngày thường, giờ lớp cuối tuần) cho GV / TA chưa có lịch dạy:
 *   GV / TA check-in từng buổi (vài buổi muộn), 1 buổi quên check-in → Học vụ chấm tay, 1 ca ngoài lịch bị từ chối, Học vụ
 *   chi nhánh duyệt ca mỗi sáng (ca 2 ngày gần nhất còn chờ đối soát).
 * - Chấm công điện thoại (ảnh + GPS) cho mọi nhân sự từ đầu tháng trước đến hôm nay: tháng trước ghi thẳng (kỳ đã khóa),
 *   tháng này bấm qua StaffAttendanceService theo đúng giờ. Đi muộn tự lập biên bản; biên bản tháng này đi đủ các bước
 *   (giải trình, chốt lỗi, phạt + nộp, phạt quá hạn, còn chờ). Đơn Bổ sung công / Xin đi muộn / Xin nghỉ ở mọi trạng thái.
 * - Bộ tiêu chí KPI mẫu cho GV full-time, Trợ giảng, Sale (vai trò chưa có bộ). Phiếu KPI tháng trước đã duyệt (1 phiếu
 *   không duyệt), phiếu tháng này chờ duyệt, phần lớn đã điền số liệu.
 * - Kỳ lương tháng này: Admin "Đồng bộ & Tính lại", nhập KPI / phụ cấp cho phiếu mới, tính lại (để Đang soát).
 * - KPI Học thuật (Trưởng Học thuật): DemoAcademicKpiSeeder (dự án học thuật, việc giao, order học liệu, phiếu tháng trước / này).
 * - Kỳ lương tháng trước đủ mọi vai trò (completeLastMonth): lớp K27 cho GV / TA chưa có lịch tháng trước (có buổi đi muộn, quên
 *   check-in, vắng, ca bị từ chối), biên bản vi phạm lập tay cho mọi vai trò ở đủ các bước, KPI bộ mẫu Quản lý cơ sở và mỗi vai trò
 *   1 người tốt / 1 người kém; mở lại kỳ demo, tính lại, duyệt, từ ngày 10 ghi nhận đã chi trả.
 *
 * Không nằm trong DatabaseSeeder: chạy tay bằng `php artisan demo:luong` sau `db:seed` (CSDL demo). Idempotent: lớp DEMO-CG-IF1
 * đã có thì bỏ qua phần chính, lớp DEMO-CG-SPK27 đã có thì bỏ qua phần tháng trước. Mỗi phần chạy trong 1 transaction.
 */
class DemoPayrollSeeder extends Seeder
{
    use InvokesControllersAsUser;

    public const MARKER = '[demo-luong]';

    private const FIRST_CLASS = 'DEMO-CG-IF1';

    private const STAFF = [
        'admin' => 'admin@menglish.edu.vn',
        'manager_cg' => 'manager@menglish.edu.vn',
        'manager_bd' => 'manager.bd@menglish.edu.vn',
        'academic_cg' => 'nva@menglish.edu.vn',
        'academic_bd' => 'giaovu2@menglish.edu.vn',
    ];

    /** Toạ độ cơ sở (gần đúng) cho cơ sở chưa cài chấm công. */
    private const BRANCH_GEO = [
        'CG' => [21.036237, 105.790583],
        'BD' => [21.034021, 105.814232],
        'DD' => [21.018131, 105.829587],
    ];

    /** Nhân sự mới vào làm từ đầu tháng này: [mã, tên, email, SĐT, cơ sở, vai trò, loại hợp đồng, lương cơ bản]. */
    private const NEW_STAFF = [
        'pt_cg' => ['ME-0201', 'Phạm Minh Đức', 'gv.minhduc@menglish.edu.vn', '0902000201', 'CG', Roles::TEACHER_PARTTIME, 'Bán thời gian', 0],
        'pt_bd' => ['ME-0202', 'Vũ Thảo Nguyên', 'gv.thaonguyen@menglish.edu.vn', '0902000202', 'BD', Roles::TEACHER_PARTTIME, 'Bán thời gian', 0],
        'ft_dd' => ['ME-0203', 'Nguyễn Hà My', 'gv.hamy@menglish.edu.vn', '0902000203', 'DD', Roles::TEACHER_FULLTIME, 'Toàn thời gian', 11500000],
        'ta_bd' => ['ME-0204', 'Lương Thùy Dung', 'ta.thuydung@menglish.edu.vn', '0902000204', 'BD', Roles::ASSISTANT, 'Bán thời gian', 0],
        'ta_dd' => ['ME-0205', 'Trịnh Gia Bảo', 'ta.giabao@menglish.edu.vn', '0902000205', 'DD', Roles::ASSISTANT, 'Bán thời gian', 0],
        'academic_dd' => ['ME-0206', 'Đinh Phương Thảo', 'giaovu.dd@menglish.edu.vn', '0902000206', 'DD', Roles::ACADEMIC_STAFF, 'Toàn thời gian', 8000000],
        'sales_new' => ['ME-0207', 'Vương Khánh Linh', 'sale.khanhlinh@menglish.edu.vn', '0902000207', 'CG', Roles::SALES_CONSULTANT, 'Toàn thời gian', 6500000],
    ];

    /** Hồ sơ lương điền cho nhân sự cũ còn trống (chỉ điền ô trống, không ghi đè). */
    private const PROFILE_FILL = [
        'manager@menglish.edu.vn' => ['Toàn thời gian', 14000000],
        'manager.bd@menglish.edu.vn' => ['Toàn thời gian', 13500000],
        'ttb@menglish.edu.vn' => ['Toàn thời gian', 12000000],
        'ketoan2@menglish.edu.vn' => ['Toàn thời gian', 12000000],
        'ketoan.moi@menglish.edu.vn' => ['Thử việc', 8500000],
        'levanvu@menglish.edu.vn' => ['Toàn thời gian', 7000000],
        'gv.banthoigian1@menglish.edu.vn' => ['Bán thời gian', 0],
        'gv.native1@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.tuan@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.tram@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.yen@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.thu@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.hai@menglish.edu.vn' => ['Bán thời gian', 0],
        'ta.linh@menglish.edu.vn' => ['Bán thời gian', 0],
    ];

    /** Đơn giá buổi riêng cho GV / TA chưa có đơn giá: email (hoặc khóa nhân sự mới) => [đơn giá, loại GV]. */
    private const RATES = [
        'gv.banthoigian1@menglish.edu.vn' => [230000, 'parttime'],
        'ta.tram@menglish.edu.vn' => [110000, 'assistant'],
        'ta.thu@menglish.edu.vn' => [110000, 'assistant'],
        'ta.hai@menglish.edu.vn' => [120000, 'assistant'],
        'ta.linh@menglish.edu.vn' => [110000, 'assistant'],
        'pt_cg' => [240000, 'parttime'],
        'pt_bd' => [230000, 'parttime'],
        'ta_bd' => [110000, 'assistant'],
        'ta_dd' => [110000, 'assistant'],
    ];

    /**
     * Lớp khai giảng K28 đầu tháng này: mã => [tên, cơ sở, từ khóa khóa học, GV, TA (một hoặc xoay vòng), phòng, lịch].
     * Lịch: [thứ ISO (1 = T2 … 7 = CN), giờ bắt đầu, giờ kết thúc, tên ca].
     */
    private const CLASSES = [
        'DEMO-CG-IF1' => ['# IELTS Intensive · K28 (CG)', 'CG', 'IELTS 6.5', 'pt_cg', ['ta.tram@menglish.edu.vn'], 'Phòng 201',
            [[2, '19:30', '21:00', 'Ca 2'], [4, '19:30', '21:00', 'Ca 2'], [6, '15:00', '16:30', 'Ca chiều']]],
        'DEMO-CG-SPK1' => ['# Speaking Club Movers · K28 (CG)', 'CG', 'Movers', 'gv.native1@menglish.edu.vn',
            ['ta.thu@menglish.edu.vn', 'ta.hai@menglish.edu.vn', 'ta.linh@menglish.edu.vn'], 'Phòng 202',
            [[1, '19:30', '21:00', 'Ca 2'], [3, '19:30', '21:00', 'Ca 2'], [7, '16:00', '17:30', 'Ca chiều']]],
        'DEMO-BD-GT1' => ['# Giao tiếp Pro B1 · K28 (BD)', 'BD', 'Giao tiếp Pro B1', 'pt_bd', ['ta_bd'], 'Phòng 302',
            [[2, '18:00', '19:30', 'Ca 1'], [4, '18:00', '19:30', 'Ca 1'], [6, '17:00', '18:30', 'Ca chiều']]],
        'DEMO-DD-GT1' => ['# Giao tiếp Teens B1 · K28 (ĐĐ)', 'DD', 'Giao tiếp Pro B1', 'gv.banthoigian1@menglish.edu.vn', ['ta_dd'], 'Phòng 101',
            [[1, '18:00', '19:30', 'Ca 1'], [3, '18:00', '19:30', 'Ca 1'], [5, '18:00', '19:30', 'Ca 1']]],
        'DEMO-DD-IE1' => ['# IELTS Foundation · K28 (ĐĐ)', 'DD', 'IELTS 6.5', 'ft_dd', ['ta_dd'], 'Phòng 102',
            [[2, '19:30', '21:00', 'Ca 2'], [4, '19:30', '21:00', 'Ca 2']]],
    ];

    /**
     * Bộ tiêu chí KPI mẫu cho vai trò chưa có bộ: [nhóm, tên, trọng số, cách đo, đơn vị, bậc, theo tỉ lệ, đủ điểm khi, loại trừ, nguồn tự động, mô tả].
     */
    private const KPI_SETS = [
        Roles::TEACHER_FULLTIME => [
            ['Tuân thủ & Chuyên cần', 'Nghỉ dạy không phép', 0, 'count', 'buoi', [[0, 100]], false, null, true, 'teacher_absent_unexcused', 'Có từ 1 buổi nghỉ không phép trong tháng là mất toàn bộ KPI tháng.'],
            ['Tuân thủ & Chuyên cần', 'Đi muộn giờ dạy', 10, 'count', 'lan', [[1, 100], [2, 50]], false, null, false, 'teacher_late', 'Số buổi check-in muộn trong tháng: ≤ 1 lần đủ điểm, 2 lần một nửa, từ 3 lần là 0.'],
            ['Chất lượng học tập', 'Kết quả Test (điểm + tỷ lệ đạt chuẩn)', 25, 'rate', 'phan_tram', null, true, 100, false, 'test_result', 'Điểm TB × 50% + tỷ lệ đạt chuẩn × 50% của các lớp phụ trách.'],
            ['Chất lượng học tập', 'Tỷ lệ chuyên cần học sinh của lớp', 15, 'rate', 'phan_tram', [[90, 100], [80, 50]], false, null, false, 'class_attendance_rate', '≥ 90% đủ điểm, 80–89% một nửa, dưới 80% là 0.'],
            ['Chất lượng giảng dạy', 'Điểm dự giờ / quan sát lớp', 20, 'rate', 'phan_tram', null, true, 100, false, null, 'Điểm trung bình các lần dự giờ trong tháng (thang 100).'],
            ['Báo cáo & Phối hợp', 'Báo cáo tháng đúng hạn', 10, 'rate', 'phan_tram', null, true, 100, false, 'monthly_report_on_time', 'Nộp báo cáo tháng trước 12h ngày mùng 2.'],
            ['Đào tạo nội bộ', 'Buổi đào tạo / sinh hoạt chuyên môn vắng mặt', 20, 'count', 'buoi', [[0, 100], [1, 50]], false, null, false, null, 'Số buổi bắt buộc vắng mặt trong tháng.'],
        ],
        Roles::ASSISTANT => [
            ['Tuân thủ & Chuyên cần', 'Đi muộn ca trợ giảng', 20, 'count', 'lan', [[0, 100], [1, 50]], false, null, false, 'teacher_late', 'Số ca check-in muộn: 0 lần đủ điểm, 1 lần một nửa, từ 2 lần là 0.'],
            ['Hỗ trợ lớp học', 'Điểm danh & nhận xét buổi học đúng hạn', 25, 'rate', 'phan_tram', null, true, 100, false, null, 'Số buổi điểm danh + nhập nhận xét trong 24h ÷ số buổi trợ giảng (%).'],
            ['Hỗ trợ lớp học', 'Tỷ lệ chuyên cần học sinh của lớp', 15, 'rate', 'phan_tram', [[90, 100], [80, 50]], false, null, false, 'class_attendance_rate', '≥ 90% đủ điểm, 80–89% một nửa.'],
            ['Hỗ trợ lớp học', 'Nhắc & thu bài tập về nhà', 20, 'rate', 'phan_tram', [[95, 100], [90, 80], [85, 60]], false, null, false, 'homework_rate', '≥ 95% đủ điểm, ≥ 90% được 80%, ≥ 85% được 60%.'],
            ['Phối hợp', 'Phản ánh của GV chính / phụ huynh', 20, 'count', 'lan', [[0, 100], [1, 50]], false, null, false, null, 'Số phản ánh đã xác minh trong tháng.'],
        ],
        Roles::MANAGER => [
            ['Kinh doanh', 'Doanh thu học phí so với kế hoạch tháng', 30, 'rate', 'phan_tram', [[100, 100], [90, 70], [80, 40]], false, null, false, null, '≥ 100% kế hoạch đủ điểm, 90–99% được 70%, 80–89% được 40%.'],
            ['Kinh doanh', 'Tỷ lệ học viên tái tục', 20, 'rate', 'phan_tram', [[85, 100], [75, 60]], false, null, false, null, '≥ 85% đủ điểm, 75–84% được 60%.'],
            ['Vận hành', 'Hồ sơ công nợ quá hạn trên 7 ngày', 20, 'count', 'ho_so', [[2, 100], [5, 50]], false, null, false, null, '≤ 2 hồ sơ đủ điểm, 3–5 hồ sơ một nửa, từ 6 hồ sơ là 0.'],
            ['Vận hành', 'Duyệt đơn / chốt biên bản trong 24h', 15, 'rate', 'phan_tram', null, true, 100, false, null, 'Số đơn / biên bản xử lý trong 24h ÷ tổng số (%).'],
            ['Nhân sự', 'Phản ánh của phụ huynh về cơ sở', 15, 'count', 'lan', [[0, 100], [1, 50]], false, null, false, null, 'Số phản ánh đã xác minh về cơ sở vật chất, thái độ nhân sự.'],
        ],
        Roles::SALES_CONSULTANT => [
            ['Chăm sóc khách hàng', 'Lead trễ hạn SLA (liên hệ, follow, kết quả test)', 30, 'count', 'case', [[2, 100], [5, 50]], false, null, false, 'crm_sla_late', '≤ 2 case đủ điểm, 3–5 case một nửa, từ 6 case là 0.'],
            ['Chăm sóc khách hàng', 'Báo cáo ngày nộp muộn', 15, 'count', 'lan', [[1, 100], [3, 50]], false, null, false, 'daily_report_late', 'Báo cáo ngày nộp sau 9h sáng hôm sau.'],
            ['Kết quả tuyển sinh', 'Tỷ lệ chốt lead → học viên', 35, 'rate', 'phan_tram', [[25, 100], [15, 50]], false, null, false, null, 'Số khách chốt ÷ số lead được giao trong tháng: ≥ 25% đủ điểm, 15–24% một nửa.'],
            ['Kết quả tuyển sinh', 'Phản ánh tư vấn sai thông tin', 20, 'count', 'lan', [[0, 100], [1, 50]], false, null, false, null, 'Số phản ánh đã xác minh của phụ huynh về tư vấn sai khóa / học phí / lịch.'],
        ],
    ];

    /**
     * Lớp K27 đã kết thúc tháng trước cho GV / TA chưa có lịch dạy tháng trước (GV full-time chưa có lớp, GV part-time, GVNN,
     * trợ giảng): [tên, cơ sở, khóa học, GV, trợ giảng (luân phiên), phòng, lịch [thứ, từ, đến, ca]].
     */
    private const LAST_MONTH_CLASSES = [
        'DEMO-CG-SPK27' => ['# Speaking Club Starters · K27 (CG)', 'CG', 'Movers', 'gv.native1@menglish.edu.vn',
            ['ta.thu@menglish.edu.vn', 'ta.hai@menglish.edu.vn', 'ta.linh@menglish.edu.vn'], 'Phòng 202',
            [[1, '19:30', '21:00', 'Ca 2'], [3, '19:30', '21:00', 'Ca 2'], [7, '16:00', '17:30', 'Ca chiều']]],
        'DEMO-CG-IE27' => ['# IELTS Pre · K27 (CG)', 'CG', 'IELTS 6.5', 'gv.cohuu1@menglish.edu.vn', ['ta.tram@menglish.edu.vn'], 'Phòng 201',
            [[2, '19:30', '21:00', 'Ca 2'], [4, '19:30', '21:00', 'Ca 2'], [6, '15:00', '16:30', 'Ca chiều']]],
        'DEMO-DD-GT27' => ['# Giao tiếp Teens A2 · K27 (ĐĐ)', 'DD', 'Giao tiếp Pro B1', 'gv.banthoigian1@menglish.edu.vn', [], 'Phòng 101',
            [[1, '18:00', '19:30', 'Ca 1'], [3, '18:00', '19:30', 'Ca 1'], [5, '18:00', '19:30', 'Ca 1']]],
    ];

    /** Mức KPI điền tay theo người (mỗi vai trò 1 người tốt, 1 người kém); người khác điền ngẫu nhiên phần lớn đạt. */
    private const KPI_PROFILES = [
        'manager@menglish.edu.vn' => 'good', 'ttb@menglish.edu.vn' => 'bad',
        'nva@menglish.edu.vn' => 'good', 'giaovu2@menglish.edu.vn' => 'bad',
        'levanvu@menglish.edu.vn' => 'good', 'hoangthinh@menglish.edu.vn' => 'bad',
        'gv.cohuu2@menglish.edu.vn' => 'good', 'gv.cohuu1@menglish.edu.vn' => 'bad',
        'gv.banthoigian1@menglish.edu.vn' => 'good', 'nguyenvanan@menglish.edu.vn' => 'bad',
        'ta.hai@menglish.edu.vn' => 'good', 'ta.linh@menglish.edu.vn' => 'bad',
    ];

    /** Sự cố buổi dạy lớp K27 (tháng trước): email => [buổi thứ n của người đó => [late | forgot | absent, số phút muộn]]. */
    private const LAST_MONTH_TEACHING_ISSUES = [
        'gv.banthoigian1@menglish.edu.vn' => [3 => ['late', 8], 7 => ['late', 12], 10 => ['forgot', null]],
        'gv.native1@menglish.edu.vn' => [5 => ['late', 22], 11 => ['late', 5]],
        'gv.cohuu1@menglish.edu.vn' => [4 => ['late', 18]],
        'ta.tram@menglish.edu.vn' => [6 => ['late', 6]],
        'ta.hai@menglish.edu.vn' => [2 => ['absent', null]],
    ];

    /**
     * Biên bản vi phạm lập tay cho mọi vai trò (ngoài biên bản đi muộn tự động): [email, loại lỗi, lỗi, ghi chú, thời điểm
     * (['L', ngày] = tháng trước, ['ago', n] = n ngày trước), diễn biến, tiền phạt, giải trình]. Diễn biến: pending (chờ giải
     * trình), explained (chờ chốt), confirmed (chốt lỗi, chưa phạt), fined (đã phạt, còn hạn nộp), paid (nộp trực tiếp),
     * remedied (nộp rồi khắc phục), deducted (quá hạn nộp → trừ lương kỳ đó; tháng trước thì đã khắc phục), resolved (miễn phạt),
     * cancelled (lập nhầm, hủy).
     */
    private const VIOLATIONS = [
        ['gv.cohuu2@menglish.edu.vn', 'academic', 'Chậm nộp nhận xét buổi học (> 24h)', 'Nhận xét 2 buổi FAM 1 BD nộp sau 48h, PH hỏi trên nhóm lớp.', ['L', 5], 'deducted', 100000,
            'Tuần đó em ốm, đã nhờ TA nhắn PH nhưng chưa kịp nhập nhận xét lên hệ thống.'],
        ['ta.tuan@menglish.edu.vn', 'operations', 'Không check-in / điểm danh đúng giờ', 'Buổi tối thứ 3 không điểm danh, Học vụ phải gọi PH xác nhận.', ['L', 9], 'remedied', 50000,
            'Máy tính bảng của lớp hết pin, em ghi giấy rồi quên nhập lại.'],
        ['tranmaia@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Báo sai mức học phí khóa Starters cho PH (thiếu phí giáo trình).', ['L', 12], 'deducted', 200000,
            'Em dùng bảng giá cũ, chưa cập nhật bảng giá tháng này.'],
        ['manager.bd@menglish.edu.vn', 'operations', 'Không chốt sổ quỹ cuối ngày đúng hạn', 'Sổ quỹ BD ngày 15 chốt trễ sang sáng hôm sau.', ['L', 16], 'confirmed', null,
            'Tối đó cơ sở có sự cố mất điện nên em chốt sổ sáng hôm sau.'],
        ['academiclead@menglish.edu.vn', 'academic', 'Không nộp giáo án / bài tập đúng hạn', 'Đề Big Test kỳ 3 duyệt trễ 1 ngày so với lịch.', ['L', 20], 'resolved', null,
            'Đề phải sửa lại theo góp ý GVNN nên duyệt trễ 1 ngày, lớp vẫn thi đúng lịch.'],
        ['nguyenvanan@menglish.edu.vn', 'operations', 'Nghỉ dạy không phép', 'Nghỉ buổi FAM 0 CG, báo trước 30 phút, Học vụ phải dạy thay.', ['L', 22], 'deducted', 300000, null],
        ['giaovu2@menglish.edu.vn', 'operations', 'Quá hạn SLA chăm sóc học viên tháng đầu', 'Lập nhầm người phụ trách (HV thuộc Học vụ Cầu Giấy).', ['L', 24], 'cancelled', null, null],
        ['gv.native1@menglish.edu.vn', 'academic', 'Dạy sai tiến độ giáo trình', 'Speaking Club dạy vượt 1 unit so với giáo trình đã giao.', ['ago', 9], 'deducted', 150000,
            'I followed the old syllabus file, I will catch up with the right unit next class.'],
        ['hoangthinh@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Không cập nhật CRM sau 3 cuộc gọi tư vấn trong ngày.', ['ago', 7], 'paid', 100000,
            'Hôm đó em đi sự kiện ở trường, về muộn nên chưa nhập kịp.'],
        ['gv.banthoigian1@menglish.edu.vn', 'academic', 'Chậm nộp nhận xét buổi học (> 24h)', 'Nhận xét buổi Giao tiếp Teens nộp sau 30 giờ.', ['ago', 6], 'remedied', 50000,
            'Em đi dạy liền 2 ca nên nhập nhận xét muộn, em đã đặt nhắc lịch.'],
        ['gv.cohuu1@menglish.edu.vn', 'academic', 'Không nhập điểm / kết quả kiểm tra đúng hạn', 'Điểm mini test lớp IELTS chưa nhập sau 3 ngày.', ['ago', 5], 'confirmed', null,
            'Bài viết cần chấm kỹ nên em nhập chậm, đã nhập xong hôm qua.'],
        ['ketoan2@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Lập phiếu thu sai tên học viên, phải hủy hóa đơn lập lại.', ['ago', 4], 'deducted', 200000,
            'Hai học viên trùng tên, em chọn nhầm hồ sơ.'],
        ['nva@menglish.edu.vn', 'operations', 'Không check-in / điểm danh đúng giờ', 'Không mở điểm danh cho lớp FAM 1 CG, GV phải chờ 10 phút.', ['ago', 3], 'resolved', null,
            'Hệ thống báo lỗi đăng nhập lúc 17h45, em đã báo IT và mở lại được ngay sau đó.'],
        ['manager@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Không duyệt đơn nghỉ của nhân sự trong 24h.', ['ago', 2], 'fined', 100000,
            'Em đi công tác cơ sở Ba Đình, không kiểm tra hệ thống.'],
        ['ta.tram@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Dùng điện thoại cá nhân trong giờ lớp IELTS.', ['ago', 2], 'pending', null, null],
        ['levanvu@menglish.edu.vn', 'operations', 'Vi phạm nội quy trung tâm', 'Báo sai lịch khai giảng lớp K28 cho PH.', ['ago', 1], 'explained', null,
            'Em xem nhầm lịch lớp Cầu Giấy, đã gọi lại xin lỗi PH ngay trong ngày.'],
    ];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var list<Penalty> biên bản tháng trước để quá hạn nộp (trừ vào kỳ lương tháng trước) */
    private array $deductedPenalties = [];

    /** @var array<string, Branch> mã cơ sở => cơ sở */
    private array $branches = [];

    /** @var array<int, true> nhân sự được điền hồ sơ lương / mới tạo (nhập tay phiếu lương kỳ này) */
    private array $touchedProfiles = [];

    /** @var list<array{0: Carbon, 1: int, 2: Closure}> */
    private array $events = [];

    /** @var array<int, array<string, array{0: string, 1: string}>> user => ngày => [giờ bắt đầu buổi đầu, giờ kết thúc buổi cuối] */
    private array $sessionDays = [];

    /** @var array<int, array<string, string>> user => ngày => 'late' | 'no_in' | 'no_out' | 'off' */
    private array $forced = [];

    /** @var array<int, array<string, string>> user => ngày => giờ check-in buổi đầu (HH:MM) dùng chung cho chấm công điện thoại */
    private array $teachIn = [];

    /** @var array<int, string> mã cơ sở theo branch_id */
    private array $branchCode = [];

    private Carbon $realNow;

    private Carbon $thisMonth;

    private Carbon $lastMonth;

    private StaffAttendanceService $attendance;

    /** @var array<int, string> user_id => đường dẫn ảnh dùng chung (tháng trước, ghi thẳng) */
    private array $sharedPhotos = [];

    public function run(): void
    {
        if (! User::where('email', self::STAFF['manager_cg'])->exists() || ! User::where('email', self::STAFF['academic_bd'])->exists()) {
            $this->command?->warn('DemoPayrollSeeder: chưa có tài khoản mẫu của UserSeeder — bỏ qua.');

            return;
        }

        // Nhân sự mới của demo dùng mật khẩu chung như tài khoản UserSeeder (UserSeeder đặt lại mỗi lần seed): đặt lại theo.
        User::whereIn('email', array_column(self::NEW_STAFF, 2))->toBase()->update(['password' => Hash::make(config('access.seed_password'))]);

        $this->attendance = app(StaffAttendanceService::class);
        $this->staff = collect(self::STAFF)->map(fn (string $email) => User::where('email', $email)->firstOrFail())->all();
        foreach (Branch::whereIn('code', array_keys(self::BRANCH_GEO))->get() as $branch) {
            $this->branches[$branch->code] = $branch;
            $this->branchCode[$branch->id] = $branch->code;
        }
        $this->realNow = now()->copy();
        $this->thisMonth = $this->realNow->copy()->startOfMonth();
        $this->lastMonth = $this->thisMonth->copy()->subMonthNoOverflow()->startOfMonth();

        if (ClassModel::withTrashed()->where('code', self::FIRST_CLASS)->exists()) {
            $this->command?->info('DemoPayrollSeeder: đã có dữ liệu demo lương — chỉ bổ sung phần còn thiếu.');
        } else {
            $this->travel(function () {
                $this->setupBranches();
                $this->setupStaff();
                $this->setupRates();
                $this->setupClasses();
                $this->setupKpiCriteria();
                $this->loadSessionDays();

                $this->planRequests();
                $this->planTeaching();
                $this->planAttendance();
                $this->planKpi();
                $this->runEvents();

                $this->processLatePenalties();
                $this->runEvents();

                $this->closePayroll();
            });
        }

        // KPI Học thuật (dự án, việc giao, order học liệu, phiếu Trưởng Học thuật): seeder riêng, chạy được cả khi đã có dữ liệu lương.
        $this->call(DemoAcademicKpiSeeder::class);
        // Kỳ lương tháng trước đủ mọi vai trò (sau KPI Học thuật để phiếu lương lấy đúng KPI đã chốt).
        $this->travel(fn () => $this->completeLastMonth());
        $this->printSummary();
    }

    /** Chạy trong 1 transaction với đồng hồ tua; luôn trả lại giờ thật, request và người đăng nhập. */
    private function travel(Closure $callback): void
    {
        $previousTestNow = Carbon::getTestNow();
        $originalRequest = app('request');
        try {
            DB::transaction($callback);
        } finally {
            Carbon::setTestNow($previousTestNow);
            app()->instance('request', $originalRequest);
            Auth::forgetUser();
        }
    }

    private function at(Carbon $at): void
    {
        Carbon::setTestNow($at->copy());
    }

    private function event(Carbon $at, Closure $callback): void
    {
        $this->events[] = [$at->copy(), count($this->events), $callback];
    }

    /** Chạy sự kiện theo thời gian; sự kiện chưa tới (sau "bây giờ") bị bỏ qua. */
    private function runEvents(): void
    {
        $events = array_filter($this->events, fn (array $e) => $e[0]->lt($this->realNow));
        usort($events, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);
        $this->events = [];
        foreach ($events as [$at, , $callback]) {
            $this->at($at);
            $callback();
        }
        $this->at($this->realNow);
    }

    /** 0–99 cố định theo (người, ngày, khóa) — dữ liệu chạy lại vẫn giống nhau. */
    private function roll(int $userId, string $date, string $salt): int
    {
        return crc32("{$userId}|{$date}|{$salt}") % 100;
    }

    private function between(int $userId, string $date, string $salt, int $min, int $max): int
    {
        return $min + crc32("{$userId}|{$date}|{$salt}|n") % ($max - $min + 1);
    }

    private function isHoliday(Carbon $day): bool
    {
        static $holidays = null;
        $holidays ??= Holiday::query()->get(['start_date', 'end_date']);

        return $holidays->contains(fn (Holiday $h) => $day->betweenIncluded(Carbon::parse($h->start_date)->startOfDay(), Carbon::parse($h->end_date ?? $h->start_date)->endOfDay()));
    }

    /** Ngày làm việc thứ n (T2–T7, không lễ) của tháng này, chỉ khi đã qua (trước hôm nay); null nếu chưa tới. */
    private function pastWorkday(int $n): ?Carbon
    {
        $count = 0;
        for ($day = $this->thisMonth->copy(); $day->lt($this->realNow->copy()->startOfDay()); $day->addDay()) {
            if ($day->isSunday() || $this->isHoliday($day)) {
                continue;
            }
            if (++$count === $n) {
                return $day->copy();
            }
        }

        return null;
    }

    private function user(string $key): ?User
    {
        return $this->staff[$key] ?? (str_contains($key, '@') ? ($this->staff[$key] = User::where('email', $key)->first()) : null);
    }

    // ── Cài đặt ────────────────────────────────────────────────────────────────

    private function setupBranches(): void
    {
        $this->at($this->lastMonth->copy()->subDays(10)->setTime(9, 0));
        foreach ($this->branches as $code => $branch) {
            if ($branch->hasCheckinLocation()) {
                continue;
            }
            [$lat, $lng] = self::BRANCH_GEO[$code];
            $branch->update([
                'latitude' => $lat, 'longitude' => $lng, 'checkin_radius' => 150,
                'work_start_time' => '08:00', 'work_end_time' => '17:30', 'late_grace_minutes' => 10,
            ]);
        }
    }

    private function setupStaff(): void
    {
        $this->at($this->thisMonth->copy()->subDays(3)->setTime(10, 0));
        $password = Hash::make(config('access.seed_password'));
        foreach (self::NEW_STAFF as $key => [$code, $name, $email, $phone, $branchCode, $role, $contract, $salary]) {
            $user = User::withTrashed()->where('email', $email)->first();
            if (! $user) {
                $user = User::create([
                    'employee_code' => User::where('employee_code', $code)->exists() ? null : $code,
                    'name' => $name, 'email' => $email, 'phone' => $phone,
                    'branch_id' => $this->branches[$branchCode]->id ?? null,
                    'password' => $password, 'is_active' => true, 'email_verified_at' => now(),
                    'contract_type' => $contract, 'base_salary' => $salary,
                    'contract_start_date' => $this->thisMonth->toDateString(),
                    'contract_end_date' => $this->thisMonth->copy()->addYear()->subDay()->toDateString(),
                    'department' => $role === Roles::ACADEMIC_STAFF || $role === Roles::SALES_CONSULTANT ? 'Vận hành' : 'Đào tạo',
                ]);
                $user->syncRoles([$role]);
                $this->touchedProfiles[$user->id] = true;
            }
            $this->staff[$key] = $user;
        }

        foreach (self::PROFILE_FILL as $email => [$contract, $salary]) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }
            $fill = array_filter([
                'contract_type' => blank($user->contract_type) ? $contract : null,
                'base_salary' => (float) $user->base_salary === 0.0 && $salary > 0 ? $salary : null,
            ]);
            if ($fill) {
                $user->forceFill($fill)->save();
                $this->touchedProfiles[$user->id] = true;
            }
        }
    }

    private function setupRates(): void
    {
        foreach (self::RATES as $key => [$amount, $type]) {
            $user = $this->user($key);
            if (! $user || TeacherHourlyRate::where('user_id', $user->id)->exists()) {
                continue;
            }
            $isNew = isset(self::NEW_STAFF[$key]);
            $from = $isNew ? $this->thisMonth->copy() : $this->lastMonth->copy()->subMonthNoOverflow();
            $this->at($from->copy()->subDays(2)->setTime(14, 0));
            $code = $this->branchCode[$user->branch_id] ?? 'CG';
            $actor = match ($code) {
                'CG' => $this->staff['manager_cg'],
                'BD' => $this->staff['manager_bd'],
                default => $this->staff['admin'],
            };
            $this->asUser($actor, PayrollController::class, 'storePersonalTeacherRate', [
                'user_id' => $user->id, 'hourly_rate' => $amount, 'rate_unit' => TeacherHourlyRate::UNIT_SESSION,
                'teacher_type' => $type, 'effective_from' => $from->toDateString(),
                'note' => ($isNew ? 'Đơn giá theo hợp đồng nhân sự mới ' : 'Đơn giá buổi theo hợp đồng ').self::MARKER,
            ]);
        }
    }

    private function setupClasses(): void
    {
        $this->at($this->thisMonth->copy()->subDays(5)->setTime(9, 0));
        $start = $this->thisMonth->copy();
        $end = $this->thisMonth->copy()->addMonthsNoOverflow(2)->endOfMonth()->subDays(8);
        $today = $this->realNow->copy()->startOfDay();

        foreach (self::CLASSES as $code => [$name, $branchCode, $courseKeyword, $teacherKey, $assistantKeys, $room, $schedule]) {
            $branch = $this->branches[$branchCode] ?? null;
            $teacher = $this->user($teacherKey);
            $assistants = collect($assistantKeys)->map(fn ($k) => $this->user($k))->filter()->values();
            if (! $branch || ! $teacher) {
                continue;
            }
            $course = Course::where('name', 'like', "%{$courseKeyword}%")->first() ?? Course::query()->orderBy('id')->first();
            $days = ['', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
            $class = ClassModel::create([
                'code' => $code, 'name' => $name, 'course_id' => $course?->id, 'branch_id' => $branch->id,
                'program' => $course?->name ? trim(str_replace('#', '', $course->name)) : null, 'level' => 'K28',
                'teacher_id' => $teacher->id, 'assistant_id' => $assistants->first()?->id, 'room' => $room,
                'schedule_text' => collect($schedule)->map(fn ($s) => "{$days[$s[0]]} {$s[1]}-{$s[2]}")->implode('; '),
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'max_capacity' => 14, 'min_students' => 6, 'tuition_fee' => $course?->tuition_fee,
                'status' => 'active', 'notes' => 'Lớp khai giảng K28 (dữ liệu mẫu lương '.self::MARKER.').',
            ]);

            $n = 0;
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                foreach ($schedule as [$weekday, $from, $to, $shift]) {
                    if ($day->dayOfWeekIso !== $weekday || $this->isHoliday($day)) {
                        continue;
                    }
                    ClassSession::create([
                        'class_id' => $class->id, 'branch_id' => $branch->id, 'date' => $day->toDateString(),
                        'shift_name' => $shift, 'type' => ClassSession::TYPE_REGULAR, 'start_time' => $from, 'end_time' => $to,
                        'room' => $room, 'teacher_id' => $teacher->id,
                        'assistant_id' => $assistants->isEmpty() ? null : $assistants[$n % $assistants->count()]->id,
                        'status' => $day->lt($today) ? 'completed' : 'scheduled',
                    ]);
                    $n++;
                }
            }
        }
    }

    private function setupKpiCriteria(): void
    {
        $this->at($this->lastMonth->copy()->subDays(5)->setTime(10, 0));
        foreach (self::KPI_SETS as $role => $items) {
            if (KpiCriterion::forRole($role)->active()->exists()) {
                continue;
            }
            foreach ($items as $i => [$group, $name, $weight, $measure, $unit, $tiers, $linear, $fullAt, $knockout, $source, $description]) {
                KpiCriterion::create([
                    'role' => $role, 'group_name' => $group, 'code' => sprintf('M%d', $i + 1), 'name' => $name, 'weight' => $weight,
                    'measure' => $measure, 'unit' => $unit, 'tiers' => $tiers, 'linear' => $linear, 'full_at' => $fullAt,
                    'per_month' => false, 'knockout' => $knockout, 'auto_source' => $source,
                    'description' => 'Bộ tiêu chí mẫu (chờ chủ dự án chốt). '.$description,
                    'is_active' => true, 'sort_order' => $i + 1,
                ]);
            }
        }
    }

    /** Ngày có buổi dạy của từng GV / TA (giờ buổi đầu – buổi cuối), từ đầu tháng trước đến hôm nay. */
    private function loadSessionDays(): void
    {
        ClassSession::query()->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$this->lastMonth->toDateString(), $this->realNow->toDateString()])
            ->get(['date', 'start_time', 'end_time', 'teacher_id', 'assistant_id', 'foreign_teacher_id'])
            ->each(function (ClassSession $s) {
                $date = $s->date->toDateString();
                foreach (array_filter([$s->teacher_id, $s->assistant_id, $s->foreign_teacher_id]) as $uid) {
                    [$from, $to] = $this->sessionDays[$uid][$date] ?? ['99:99', '00:00'];
                    $this->sessionDays[$uid][$date] = [min($from, $s->start_time->format('H:i')), max($to, $s->end_time->format('H:i'))];
                }
            });
    }

    // ── Đơn xin duyệt ──────────────────────────────────────────────────────────

    private function planRequests(): void
    {
        $admin = $this->staff['admin'];

        // Tháng trước (kỳ đã khóa): ghi thẳng đơn đã xử lý.
        $L = $this->lastMonth;
        $past = [
            ['giaovu2@menglish.edu.vn', StaffAttendanceRequest::TYPE_LEAVE, 14, 15, null, null, 'Về quê có việc gia đình (đám cưới em gái).', 'approved'],
            ['tranmaia@menglish.edu.vn', StaffAttendanceRequest::TYPE_LATE_EARLY, 9, 9, null, null, 'Đưa con đi khám, đến muộn khoảng 30 phút, đã báo Quản lý.', 'approved'],
            ['hoangthinh@menglish.edu.vn', StaffAttendanceRequest::TYPE_CORRECTION, 22, 22, null, '17:45', 'Quên chấm ra, về lúc 17:45 (có trực quầy cùng Quản lý).', 'approved'],
            ['nva@menglish.edu.vn', StaffAttendanceRequest::TYPE_CORRECTION, 17, 17, '07:55', null, 'Điện thoại hết pin lúc đến cơ sở, nhờ Quản lý xác nhận giờ vào.', 'approved'],
            ['ta.tuan@menglish.edu.vn', StaffAttendanceRequest::TYPE_LEAVE, 25, 25, null, null, 'Xin nghỉ ca tối thứ 6 để ôn thi cuối kỳ.', 'rejected'],
            ['levanvu@menglish.edu.vn', StaffAttendanceRequest::TYPE_LEAVE, 28, 29, null, null, 'Nghỉ phép năm 2 ngày.', 'approved'],
        ];
        foreach ($past as [$email, $type, $fromDay, $toDay, $in, $out, $reason, $status]) {
            $user = User::where('email', $email)->first();
            $from = $L->copy()->addDays($fromDay - 1);
            if (! $user || $from->month !== $L->month) {
                continue;
            }
            while ($from->isSunday() || $this->isHoliday($from)) {
                $from->addDay();
            }
            $to = $type === StaffAttendanceRequest::TYPE_LEAVE ? $from->copy()->addDays($toDay - $fromDay) : $from->copy();
            $date = $from->toDateString();
            if ($type === StaffAttendanceRequest::TYPE_LEAVE && $status === 'approved') {
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $this->forced[$user->id][$d->toDateString()] = 'off';
                }
            }
            if ($type === StaffAttendanceRequest::TYPE_LATE_EARLY) {
                $this->forced[$user->id][$date] = 'late';
            }
            if ($type === StaffAttendanceRequest::TYPE_CORRECTION) {
                $this->forced[$user->id][$date] = $in ? 'no_in' : 'no_out';
            }
            if ($type === StaffAttendanceRequest::TYPE_LEAVE && $status === 'rejected') {
                $this->forced[$user->id][$date] = 'off';
            }
            $submitted = $type === StaffAttendanceRequest::TYPE_CORRECTION ? $from->copy()->addDay()->setTime(8, 20) : $from->copy()->subDays(2)->setTime(20, 10);
            // Ghi sau khi đã có dòng chấm công của ngày đó (bổ sung công sửa dòng; xin muộn đánh dấu muộn có phép).
            $this->event($from->copy()->addDay()->setTime(9, 30), function () use ($user, $type, $from, $to, $in, $out, $reason, $status, $submitted, $admin) {
                $request = StaffAttendanceRequest::create([
                    'user_id' => $user->id, 'branch_id' => $user->branch_id, 'type' => $type,
                    'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(),
                    'check_in_time' => $in, 'check_out_time' => $out, 'reason' => $reason, 'status' => $status,
                    'reviewed_by' => $admin->id, 'reviewed_at' => now(),
                    'rejection_reason' => $status === 'rejected' ? 'Báo nghỉ sát giờ, lớp không có trợ giảng thay — đề nghị đi làm theo lịch.' : null,
                ]);
                $request->forceFill(['created_at' => $submitted, 'updated_at' => now()])->saveQuietly();
                if ($status === 'approved' && $type !== StaffAttendanceRequest::TYPE_LEAVE) {
                    $this->applyPastRequest($request);
                }
            });
        }

        // Tháng này: vài ngày đi muộn cố định (đủ biên bản tự động cho các bước xử lý).
        foreach (['ketoan2@menglish.edu.vn' => 1, 'levanvu@menglish.edu.vn' => 2, 'ttb@menglish.edu.vn' => 3, 'giaovu2@menglish.edu.vn' => 4, 'sale.khanhlinh@menglish.edu.vn' => 5, 'academiclead@menglish.edu.vn' => 6] as $email => $n) {
            if (($day = $this->pastWorkday($n)) && ($u = User::where('email', $email)->first())) {
                $this->forced[$u->id][$day->toDateString()] ??= 'late';
            }
        }

        // Tháng này: gửi / duyệt / từ chối / rút qua StaffAttendanceService.
        $service = $this->attendance;
        $submit = function (User $user, array $data, Carbon $at) use ($service) {
            $this->event($at, fn () => $service->submit($user, $data));
        };
        $find = fn (User $user, string $type, string $date) => StaffAttendanceRequest::where('user_id', $user->id)
            ->where('type', $type)->whereDate('date_from', $date)->latest('id')->first();
        $approve = function (User $user, string $type, Carbon $day, Carbon $at) use ($service, $find, $admin) {
            $this->event($at, function () use ($service, $find, $user, $type, $day, $admin) {
                if ($request = $find($user, $type, $day->toDateString())) {
                    $service->approve($request, $admin);
                }
            });
        };
        $today = $this->realNow->copy()->startOfDay();

        // Xin nghỉ đã duyệt (Học vụ CG, ngày làm việc thứ 3 của tháng).
        if (($day = $this->pastWorkday(3)) && ($u = User::where('email', 'nva@menglish.edu.vn')->first())) {
            $this->forced[$u->id][$day->toDateString()] = 'off';
            $submit($u, ['type' => 'leave', 'date_from' => $day->toDateString(), 'date_to' => $day->toDateString(), 'reason' => 'Nghỉ phép 1 ngày đi khám răng định kỳ, đã bàn giao việc cho Quản lý.'], $day->copy()->subDays(2)->max($this->thisMonth)->setTime(7, 5));
            $approve($u, 'leave', $day, $day->copy()->subDay()->max($this->thisMonth)->setTime(7, 30));
        }
        // Xin đi muộn được duyệt → biên bản đi muộn tự hủy (Sale CG, ngày làm việc thứ 2).
        if (($day = $this->pastWorkday(2)) && ($u = User::where('email', 'tranmaia@menglish.edu.vn')->first())) {
            $this->forced[$u->id][$day->toDateString()] = 'late';
            $submit($u, ['type' => 'late_early', 'date_from' => $day->toDateString(), 'reason' => 'Xe hỏng giữa đường, đến muộn khoảng 25 phút, đã nhắn Quản lý lúc 7h50.'], $day->copy()->setTime(10, 15));
            $approve($u, 'late_early', $day, $day->copy()->setTime(14, 0));
        }
        // Bổ sung công đã duyệt: quên chấm vào cả ngày (Sale BD, ngày làm việc thứ 4).
        if (($day = $this->pastWorkday(4)) && ($u = User::where('email', 'hoangthinh@menglish.edu.vn')->first())) {
            $this->forced[$u->id][$day->toDateString()] = 'no_in';
            $submit($u, ['type' => 'correction', 'date_from' => $day->toDateString(), 'check_in_time' => '07:55', 'check_out_time' => '17:40', 'reason' => 'Ứng dụng báo lỗi GPS cả ngày nên không chấm được, có mặt trực quầy tư vấn (Quản lý xác nhận).'], $day->copy()->addDay()->setTime(8, 15));
            $approve($u, 'correction', $day, $day->copy()->addDay()->setTime(9, 40));
        }
        // Bổ sung công bị từ chối (TA CG quên chấm ra ngày dạy đầu tiên trong tháng).
        if ($u = User::where('email', 'ta.tuan@menglish.edu.vn')->first()) {
            $day = collect(array_keys($this->sessionDays[$u->id] ?? []))->sort()->map(fn ($d) => Carbon::parse($d))
                ->first(fn (Carbon $d) => $d->gte($this->thisMonth) && $d->lt($today->copy()->subDay()));
            if ($day) {
                $this->forced[$u->id][$day->toDateString()] = 'no_out';
                $submit($u, ['type' => 'correction', 'date_from' => $day->toDateString(), 'check_out_time' => '21:30', 'reason' => 'Em quên chấm ra, về lúc 21:30 sau khi dọn phòng học.'], $day->copy()->addDay()->setTime(9, 0));
                $this->event($day->copy()->addDay()->setTime(15, 30), function () use ($service, $find, $u, $day, $admin) {
                    if ($request = $find($u, 'correction', $day->toDateString())) {
                        $service->reject($request, $admin, 'Ca dạy kết thúc 19:00, giờ ra khai 21:30 không khớp camera — khai lại đúng giờ về.');
                    }
                });
            }
        }
        // Chờ duyệt: bổ sung công (Sale ĐĐ quên chấm vào ngày làm việc gần nhất).
        if ($u = User::where('email', 'levanvu@menglish.edu.vn')->first()) {
            $day = $today->copy()->subDay();
            while ($day->isSunday() || $this->isHoliday($day)) {
                $day->subDay();
            }
            if ($day->gte($this->thisMonth)) {
                $this->forced[$u->id][$day->toDateString()] = 'no_in';
                $submit($u, ['type' => 'correction', 'date_from' => $day->toDateString(), 'check_in_time' => '08:02', 'reason' => 'Quên chấm công vào buổi sáng, có mặt từ 8h tư vấn khách walk-in.'], $day->copy()->setTime(18, 40));
            }
        }
        // Chờ duyệt: xin về sớm (TA CG) ngày mai; xin nghỉ 2 ngày tuần sau (Quản lý BD).
        if ($u = User::where('email', 'ta.hai@menglish.edu.vn')->first()) {
            $submit($u, ['type' => 'late_early', 'date_from' => $today->copy()->addDay()->toDateString(), 'reason' => 'Xin về sớm 30 phút để kịp giờ thi chứng chỉ TKT Module 2.'], $this->realNow->copy()->subHours(5));
        }
        if ($u = $this->staff['manager_bd']) {
            $submit($u, ['type' => 'leave', 'date_from' => $today->copy()->addDays(5)->toDateString(), 'date_to' => $today->copy()->addDays(6)->toDateString(), 'reason' => 'Đi khám sức khỏe định kỳ và đưa con đi tiêm phòng, đã nhờ Quản lý Trần Thị B trực thay.'], $this->realNow->copy()->subHours(3));
        }
        // Từ chối: xin nghỉ buổi dạy sắp tới (GV ĐĐ) — chưa có GV thay.
        if ($u = User::where('email', 'gv.banthoigian1@menglish.edu.vn')->first()) {
            $next = $today->copy()->addDays(2);
            $submit($u, ['type' => 'leave', 'date_from' => $next->toDateString(), 'date_to' => $next->toDateString(), 'reason' => 'Xin nghỉ buổi dạy để đi công tác cùng trường.'], $this->realNow->copy()->subHours(7));
            $this->event($this->realNow->copy()->subHours(4), function () use ($service, $find, $u, $next, $admin) {
                if ($request = $find($u, 'leave', $next->toDateString())) {
                    $service->reject($request, $admin, 'Lớp Giao tiếp Teens B1 tuần này kiểm tra giữa kỳ, chưa có GV thay — đề nghị đổi lịch công tác.');
                }
            });
        }
        // Đã rút: Sale mới xin nghỉ rồi rút đơn.
        if ($u = $this->staff['sales_new'] ?? null) {
            $day = $today->copy()->addDays(3);
            $submit($u, ['type' => 'leave', 'date_from' => $day->toDateString(), 'date_to' => $day->toDateString(), 'reason' => 'Xin nghỉ 1 ngày làm thủ tục giấy tờ.'], $this->realNow->copy()->subHours(26)->max($this->thisMonth->copy()->setTime(7, 0)));
            $this->event($this->realNow->copy()->subHours(2), function () use ($service, $find, $u, $day) {
                if ($request = $find($u, 'leave', $day->toDateString())) {
                    $service->cancel($u, $request);
                }
            });
        }
    }

    /** Áp đơn tháng trước (đã duyệt) vào dòng chấm công: bổ sung giờ / đánh dấu muộn có phép. */
    private function applyPastRequest(StaffAttendanceRequest $request): void
    {
        $user = $request->user;
        $day = $request->date_from->copy();
        $row = StaffAttendance::forDay($user->id, $day) ?? new StaffAttendance([
            'user_id' => $user->id, 'work_date' => $day->toDateString(), 'source' => StaffAttendance::SOURCE_REQUEST, 'branch_id' => $user->branch_id,
        ]);
        if ($request->type === StaffAttendanceRequest::TYPE_CORRECTION) {
            if ($request->check_in_time) {
                $row->check_in_at = $day->copy()->setTimeFromTimeString((string) $request->check_in_time);
            }
            if ($request->check_out_time) {
                $row->check_out_at = $day->copy()->setTimeFromTimeString((string) $request->check_out_time);
            }
            $row->note = 'Bổ sung công đã duyệt: '.$request->reason;
        }
        $this->attendance->recalculate($row, $user, $row->branch ?? $user->branch);
        $row->save();
        if ($row->penalty_id && ! $row->isLate()) {
            $row->penalty?->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => 'Tự hủy: đã duyệt đơn cho ngày này.']);
        }
    }

    // ── Giờ dạy (lớp K28) ──────────────────────────────────────────────────────

    private function planTeaching(): void
    {
        $classes = ClassModel::whereIn('code', array_keys(self::CLASSES))->get()->keyBy('id');
        $sessions = ClassSession::whereIn('class_id', $classes->keys())
            ->whereBetween('date', [$this->thisMonth->toDateString(), $this->realNow->toDateString()])
            ->orderBy('date')->orderBy('start_time')->get();
        $reviewers = [
            'CG' => $this->staff['academic_cg'],
            'BD' => $this->staff['academic_bd'],
            'DD' => $this->staff['academic_dd'] ?? $this->staff['admin'],
        ];

        // Buổi thứ 2 của lớp Giao tiếp BD: GV quên check-in → Học vụ chấm tay tối hôm đó.
        $bd = $classes->firstWhere('code', 'DEMO-BD-GT1');
        $forgotten = $bd ? $sessions->where('class_id', $bd->id)->values()->get(1) : null;

        foreach ($sessions as $session) {
            $start = $session->date->copy()->setTimeFromTimeString($session->start_time->format('H:i'));
            if ($start->gte($this->realNow)) {
                continue;
            }
            $date = $session->date->toDateString();
            foreach (array_filter([$session->teacher_id, $session->assistant_id]) as $uid) {
                $user = User::find($uid);
                if ($forgotten && $session->is($forgotten) && $uid === $session->teacher_id) {
                    $this->event($start->copy()->setTime(21, 15), fn () => $this->asUser($reviewers['BD'], PayrollController::class, 'storeTimesheet', [
                        'user_id' => $uid, 'class_id' => $session->class_id, 'teaching_date' => $date,
                        'time_in' => $session->start_time->format('H:i'), 'time_out' => $session->end_time->format('H:i'), 'type' => 'regular',
                        'notes' => 'GV quên check-in — đối chiếu sổ điểm danh và camera lớp, xác nhận có dạy.',
                    ]));
                    $this->teachIn[$uid][$date] = $start->copy()->subMinutes(15)->format('H:i');

                    continue;
                }
                // Buổi đầu trong ngày quyết định giờ đến; muộn 12–20 phút (~8% số ngày), còn lại đến sớm 8–25 phút.
                $isFirst = ($this->sessionDays[$uid][$date][0] ?? null) === $session->start_time->format('H:i');
                $late = $isFirst && $this->roll($uid, $date, 'late') < 8 && $user?->getRoleNames()->first() !== Roles::TEACHER_FULLTIME;
                $offset = $late ? $this->between($uid, $date, 'off', 12, 20) : -$this->between($uid, $date, 'off', 8, 25);
                $checkin = $start->copy()->addMinutes($offset + 1);
                if ($isFirst) {
                    $this->teachIn[$uid][$date] = $start->copy()->addMinutes($offset)->format('H:i');
                    if ($late) {
                        $this->forced[$uid][$date] ??= 'late';
                    }
                }
                if ($checkin->lt($this->realNow)) {
                    $this->event($checkin, fn () => $this->asUser($user, TeacherPortalController::class, 'checkin', ['session_ids' => [$session->id]]));
                }
            }
        }

        // Ca chấm tay không có buổi trên lịch (TA ĐĐ hỗ trợ sự kiện khai giảng) → bị từ chối khi duyệt.
        $ta = $this->staff['ta_dd'] ?? null;
        $dd = $classes->firstWhere('code', 'DEMO-DD-GT1');
        $eventDay = $dd ? collect(range(1, 6))->map(fn ($n) => $this->pastWorkday($n))->filter()
            ->first(fn (Carbon $d) => ! ClassSession::where('class_id', $dd->id)->whereDate('date', $d->toDateString())->exists()) : null;
        if ($ta && $dd && $eventDay) {
            $this->event($eventDay->copy()->setTime(20, 30), fn () => $this->asUser($reviewers['DD'], PayrollController::class, 'storeTimesheet', [
                'user_id' => $ta->id, 'class_id' => $dd->id, 'teaching_date' => $eventDay->toDateString(),
                'time_in' => '15:00', 'time_out' => '17:00', 'type' => 'workshop',
                'notes' => 'Hỗ trợ sự kiện khai giảng K28 (TA đề nghị tính công).',
            ]));
        }

        // Học vụ chi nhánh duyệt ca mỗi sáng 07:45 (ca của 2 ngày gần nhất còn chờ đối soát).
        for ($day = $this->thisMonth->copy()->addDay(); $day->lte($this->realNow); $day->addDay()) {
            $at = $day->copy()->setTime(7, 45);
            $cutoff = $day->copy()->subDay()->toDateString();
            $this->event($at, function () use ($classes, $reviewers, $cutoff) {
                TeacherTimesheet::with('classModel')->where('status', 'pending_review')->whereIn('class_id', $classes->keys())
                    ->whereDate('teaching_date', '<', $cutoff)->orderBy('id')->get()
                    ->each(function (TeacherTimesheet $t) use ($reviewers) {
                        $reviewer = $reviewers[$this->branchCode[$t->classModel?->branch_id] ?? 'CG'] ?? $this->staff['admin'];
                        $input = $t->class_session_id
                            ? ['decision' => 'valid']
                            : ['decision' => 'invalid', 'rejection_reason' => 'Không có buổi học trên lịch lớp ngày này — sự kiện khai giảng tính vào ngày công, không tính buổi dạy.'];
                        $this->asUser($reviewer, PayrollController::class, 'reviewTimesheet', $input, ['id' => $t->id]);
                    });
            });
        }
    }

    // ── Chấm công điện thoại ───────────────────────────────────────────────────

    private function planAttendance(): void
    {
        $staffRoles = [Roles::MANAGER, Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF, Roles::SALES_CONSULTANT, Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, Roles::ASSISTANT];
        $users = User::with('roles')->where('is_active', true)->whereIn('branch_id', array_keys($this->branchCode))
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $staffRoles))->orderBy('id')->get();
        $existingIn = TeacherTimesheet::whereBetween('teaching_date', [$this->lastMonth->toDateString(), $this->realNow->toDateString()])
            ->whereNotNull('checkin_time')->get(['user_id', 'teaching_date', 'checkin_time'])
            ->groupBy(fn ($t) => $t->user_id.'|'.Carbon::parse($t->teaching_date)->toDateString())
            ->map(fn ($rows) => $rows->min(fn ($t) => substr((string) $t->checkin_time, 0, 5)));
        $today = $this->realNow->copy()->startOfDay();

        foreach ($users as $user) {
            $role = $user->getRoleNames()->first(fn ($r) => in_array($r, $staffRoles, true));
            $teaching = in_array($role, [Roles::TEACHER_PARTTIME, Roles::ASSISTANT], true);
            $from = $this->lastMonth->copy()->max(Carbon::parse($user->contract_start_date ?? $this->lastMonth)->startOfDay());

            for ($day = $from->copy(); $day->lte($today); $day->addDay()) {
                $date = $day->toDateString();
                $session = $this->sessionDays[$user->id][$date] ?? null;
                $forced = $this->forced[$user->id][$date] ?? null;
                $workday = ! $day->isSunday() && ! $this->isHoliday($day);
                if ($forced === 'off' || ($teaching && ! $session) || (! $teaching && ! $workday && ! $session)) {
                    continue;
                }
                if ($role === Roles::TEACHER_FULLTIME && ! $session && $day->isSaturday()) {
                    continue;
                }
                // Vắng không chấm (~3% ngày làm việc của khối văn phòng, không rơi vào hôm nay).
                if (! $teaching && ! $session && $forced === null && $day->lt($today) && $this->roll($user->id, $date, 'absent') < 3) {
                    continue;
                }

                [$in, $out] = $this->punchTimes($user, $role, $day, $session, $forced, $existingIn->get($user->id.'|'.$date));
                $mobile = $day->gte($this->thisMonth);
                $workDate = $day->copy();
                if ($in && $in->lt($this->realNow)) {
                    $this->event($in, $mobile
                        ? fn () => $this->punch($user, StaffAttendanceService::IN, $in)
                        : fn () => $this->writePast($user, $workDate, $in, $out));
                }
                if ($mobile && $out && $out->lt($this->realNow) && ($in || $forced !== 'no_in')) {
                    if ($in) {
                        $this->event($out, fn () => $this->punch($user, StaffAttendanceService::OUT, $out));
                    }
                }
            }
        }
    }

    /**
     * Giờ vào / ra của một ngày. GV / TA: theo buổi dạy (đến trước buổi đầu, về sau buổi cuối; ngày đã check-in buổi dạy thì
     * vào trước giờ check-in đó vài phút). Khối văn phòng: theo giờ làm việc cơ sở 08:00–17:30.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function punchTimes(User $user, string $role, Carbon $day, ?array $session, ?string $forced, ?string $timesheetIn): array
    {
        $date = $day->toDateString();
        $uid = $user->id;
        $isToday = $day->isSameDay($this->realNow);
        $outRoll = $this->roll($uid, $date, 'out');

        if ($session) {
            [$first, $last] = $session;
            $start = $day->copy()->setTimeFromTimeString($first);
            $known = $this->teachIn[$uid][$date] ?? $timesheetIn;
            // Giờ check-in buổi dạy chỉ dùng khi đúng là buổi đầu trong ngày (không phải buổi tối khi sáng có buổi bổ trợ).
            if ($known !== null && abs($start->diffInMinutes($day->copy()->setTimeFromTimeString($known), false)) > 45) {
                $known = null;
            }
            $in = match (true) {
                $forced === 'no_in' => null,
                $known !== null => $day->copy()->setTimeFromTimeString($known)->subMinutes($this->between($uid, $date, 'pre', 1, 4)),
                $forced === 'late' => $start->copy()->addMinutes($this->between($uid, $date, 'late', 12, 20)),
                default => $start->copy()->subMinutes($this->between($uid, $date, 'early', 10, 25)),
            };
            // GV full-time ngày có lớp tối vẫn làm từ sáng ở cơ sở.
            if ($role === Roles::TEACHER_FULLTIME && ! $day->isWeekend() && $in) {
                $in = $day->copy()->setTime(8, 0)->addMinutes($this->between($uid, $date, 'ft', 5, 40));
            }
            $out = $day->copy()->setTimeFromTimeString($last)->addMinutes($this->between($uid, $date, 'after', 3, 15));
        } elseif ($role === Roles::TEACHER_FULLTIME) {
            $in = $day->copy()->setTime(8, 0)->addMinutes($this->between($uid, $date, 'ft', 5, 40));
            $out = $day->copy()->setTime(17, 0)->addMinutes($this->between($uid, $date, 'ftout', 0, 50));
        } else {
            $late = $forced === 'late' || ($forced === null && $this->roll($uid, $date, 'late') < 5);
            $in = match (true) {
                $forced === 'no_in' => null,
                $late => $day->copy()->setTime(8, 0)->addMinutes($this->between($uid, $date, 'late', 12, 38)),
                default => $day->copy()->setTime(7, 38)->addMinutes($this->between($uid, $date, 'early', 0, 28)),
            };
            $out = $outRoll >= 4 && $outRoll < 8
                ? $day->copy()->setTime(16, 40)->addMinutes($this->between($uid, $date, 'early_out', 0, 35))
                : $day->copy()->setTime(17, 31)->addMinutes($this->between($uid, $date, 'normal_out', 0, 55));
        }
        if ($forced === 'no_out' || (! $isToday && $forced === null && $outRoll < 4) || ($in && $out && $out->lte($in))) {
            $out = null;
        }

        return [$in, $out];
    }

    /** Bấm chấm công qua StaffAttendanceService (giờ máy chủ = $at), kèm ảnh + GPS cách cơ sở 5–90 m. */
    private function punch(User $user, string $kind, Carbon $at): void
    {
        $branch = $user->branch;
        if (! $branch?->hasCheckinLocation()) {
            return;
        }
        [$lat, $lng] = $this->nearby($branch, $user->id, $at->toDateString().$kind);
        $file = $this->photoFile($user, $at, $kind);
        try {
            $this->attendance->punch($user, $kind, $lat, $lng, (float) $this->between($user->id, $at->toDateString(), $kind.'acc', 6, 24), $file);
        } finally {
            @unlink($file->getPathname());
        }
    }

    /** Tháng trước (kỳ đã khóa): ghi thẳng dòng chấm công đã có giờ vào / ra, tính muộn / về sớm như khi bấm. */
    private function writePast(User $user, Carbon $day, Carbon $in, ?Carbon $out): void
    {
        $branch = $user->branch;
        if (! $branch?->hasCheckinLocation() || StaffAttendance::forDay($user->id, $day)) {
            return;
        }
        $photo = $this->sharedPhoto($user);
        [$latIn, $lngIn] = $this->nearby($branch, $user->id, $day->toDateString().'in');
        [$latOut, $lngOut] = $this->nearby($branch, $user->id, $day->toDateString().'out');
        $row = new StaffAttendance([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'work_date' => $day->toDateString(), 'source' => StaffAttendance::SOURCE_MOBILE,
            'check_in_at' => $in, 'check_in_photo' => $photo, 'check_in_lat' => $latIn, 'check_in_lng' => $lngIn,
            'check_in_distance' => $branch->distanceTo($latIn, $lngIn), 'check_in_accuracy' => $this->between($user->id, $day->toDateString(), 'acc', 6, 24),
        ]);
        if ($out) {
            $row->fill([
                'check_out_at' => $out, 'check_out_photo' => $photo, 'check_out_lat' => $latOut, 'check_out_lng' => $lngOut,
                'check_out_distance' => $branch->distanceTo($latOut, $lngOut), 'check_out_accuracy' => $this->between($user->id, $day->toDateString(), 'acc2', 6, 24),
            ]);
        }
        $this->attendance->recalculate($row, $user, $branch);
        $row->save();
        $row->forceFill(['updated_at' => $out ?? $in])->saveQuietly();

        // Đi muộn tháng trước: biên bản đã đóng (Quản lý nhắc nhở, không phạt tiền).
        if ($row->isLate()) {
            $decider = $this->deciderFor($user);
            $penalty = Penalty::create([
                'code' => Penalty::generateCode(), 'user_id' => $user->id,
                'violation_type' => 'Đi muộn '.$row->late_minutes.' phút (chấm công ngày '.$day->format('d/m/Y').')',
                'error_category' => 'operations', 'violation_date' => $day->toDateString(), 'amount' => 0, 'status' => 'resolved',
                'notes' => 'Tự động từ chấm công: giờ vào '.$in->format('H:i').', giờ phải có mặt '.substr((string) $row->expected_start, 0, 5).'.',
                'decided_by' => $decider->id, 'decided_at' => $day->copy()->addDay()->setTime(10, 0),
                'decision_note' => 'Nhắc nhở, không phạt tiền (đi muộn lần đầu trong tháng).',
            ]);
            $row->penalty()->associate($penalty)->saveQuietly();
        }
    }

    private function deciderFor(User $user): User
    {
        if ($user->hasRole(Roles::MANAGER)) {
            return $this->staff['admin'];
        }

        return match ($this->branchCode[$user->branch_id] ?? null) {
            'CG' => $this->staff['manager_cg'],
            'BD' => $this->staff['manager_bd'],
            default => $this->staff['admin'],
        };
    }

    /** @return array{0: float, 1: float} toạ độ cách cơ sở 5–90 m */
    private function nearby(Branch $branch, int $userId, string $salt): array
    {
        $meters = $this->between($userId, $salt, 'm', 5, 90);
        $angle = deg2rad($this->between($userId, $salt, 'a', 0, 359));

        return [
            round($branch->latitude + $meters * sin($angle) / 111320, 7),
            round($branch->longitude + $meters * cos($angle) / (111320 * cos(deg2rad($branch->latitude))), 7),
        ];
    }

    private function photoFile(User $user, Carbon $at, string $kind): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cc').'.jpg';
        file_put_contents($path, $this->photoBytes($user, $at, $kind));

        return new UploadedFile($path, 'cham-cong.jpg', 'image/jpeg', null, true);
    }

    private function sharedPhoto(User $user): string
    {
        if (! isset($this->sharedPhotos[$user->id])) {
            $path = StaffAttendance::PHOTO_DIR.'/demo/'.$user->id.'.jpg';
            Storage::disk('local')->put($path, $this->photoBytes($user, null, 'in'));
            $this->sharedPhotos[$user->id] = $path;
        }

        return $this->sharedPhotos[$user->id];
    }

    /** Ảnh chân dung minh họa (nền màu theo người, chữ viết tắt tên, dòng giờ chấm) — không phải ảnh thật. */
    private function photoBytes(User $user, ?Carbon $at, string $kind): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
        }
        $palette = [[37, 99, 235], [5, 150, 105], [217, 119, 6], [190, 24, 93], [124, 58, 237], [8, 145, 178], [101, 163, 13]];
        [$r, $g, $b] = $palette[$user->id % count($palette)];
        $img = imagecreatetruecolor(240, 320);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        $skin = imagecolorallocate($img, 241, 205, 170);
        $shirt = imagecolorallocate($img, (int) ($r * 0.6), (int) ($g * 0.6), (int) ($b * 0.6));
        imagefilledellipse($img, 120, 300, 230, 170, $shirt);
        imagefilledellipse($img, 120, 130, 120, 150, $skin);
        $initials = collect(explode(' ', Str::ascii(preg_replace('/\(.*\)|ThS\.\s*/u', '', $user->name))))->filter()->map(fn ($w) => strtoupper($w[0]))->take(-2)->implode('');
        $small = imagecreatetruecolor(20, 15);
        imagefill($small, 0, 0, $skin);
        imagestring($small, 5, 1, 0, $initials, imagecolorallocate($small, 60, 60, 60));
        imagecopyresampled($img, $small, 80, 108, 0, 0, 80, 60, 20, 15);
        imagefilledrectangle($img, 0, 290, 239, 319, imagecolorallocate($img, 0, 0, 0));
        $label = ($kind === StaffAttendanceService::OUT ? 'RA ' : 'VAO ').($at ? $at->format('H:i d/m/Y') : 'MENGLISH');
        imagestring($img, 3, 8, 298, $label, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 72);
        imagedestroy($img);
        imagedestroy($small);

        return (string) ob_get_clean();
    }

    // ── Biên bản đi muộn tháng này ─────────────────────────────────────────────

    /** Biên bản đi muộn tự lập trong tháng: giải trình, chốt lỗi, phạt + nộp, phạt chưa nộp (quá hạn trừ lương), còn chờ. */
    private function processLatePenalties(): void
    {
        $penalties = Penalty::query()->where('status', 'pending')->where('notes', 'like', 'Tự động từ chấm công%')
            ->whereDate('violation_date', '>=', $this->thisMonth->toDateString())->orderBy('violation_date')->orderBy('id')->get();
        $explanations = [
            'Tắc đường đoạn Cầu Giấy do mưa lớn, em đã nhắn nhóm cơ sở lúc 7h55.',
            'Con ốm sốt nên em đưa đi khám sớm, đến muộn, em xin rút kinh nghiệm.',
            'Xe hỏng giữa đường, em phải gửi xe sửa rồi bắt taxi tới.',
            'Em ngủ quên do tối trước trực sự kiện khai giảng muộn, em xin lỗi.',
            'Ứng dụng chấm công báo lỗi GPS, em chấm lại được lúc muộn hơn giờ đến thật.',
        ];
        foreach ($penalties as $i => $penalty) {
            $user = $penalty->user;
            $base = Carbon::parse($penalty->violation_date)->setTime(12, 0);
            $decider = $this->deciderFor($user);
            $step = $i % 5;
            if ($step !== 3) {
                $this->event($base->copy()->addHours(8), fn () => $penalty->fresh()->status === 'pending'
                    ? $this->asUser($user, PenaltyController::class, 'explain', ['explanation' => $explanations[$i % count($explanations)]], ['id' => $penalty->id]) : null);
            }
            $decide = function (array $input) use ($penalty, $decider) {
                if (in_array($penalty->fresh()->status, ['pending', 'explained'], true)) {
                    $this->asUser($decider, PenaltyController::class, 'confirmPenalty', $input, ['id' => $penalty->id]);
                }
            };
            match ($step) {
                0 => $this->event($base->copy()->addDay()->setTime(10, 0), fn () => $decide(['decision' => 'error', 'decision_note' => 'Có lý do chính đáng — nhắc nhở, không phạt tiền.'])),
                2 => $this->event($base->copy()->addDay()->setTime(10, 30), fn () => $decide(['decision' => 'fine', 'amount' => 50000, 'decision_note' => 'Đi muộn lần 2 trong tháng — phạt theo quy chế.'])),
                4 => $this->event($base->copy()->addDay()->setTime(11, 0), fn () => $decide(['decision' => 'fine', 'amount' => 100000, 'decision_note' => 'Muộn > 30 phút không báo trước.'])),
                default => null,
            };
            if ($step === 2) {
                $this->event($base->copy()->addDays(2)->setTime(9, 0), function () use ($penalty) {
                    if ($penalty->fresh()->status === 'fined') {
                        $actor = $this->deciderFor($penalty->user);
                        $actor = $actor->can('violation.mark_paid') && $actor->id !== $penalty->user_id ? $actor : $this->staff['admin'];
                        $this->asUser($actor, PenaltyController::class, 'markPaidPenalty', [], ['id' => $penalty->id]);
                    }
                });
            }
        }
    }

    // ── KPI ────────────────────────────────────────────────────────────────────

    private function planKpi(): void
    {
        $sheets = app(KpiSheetService::class);

        // Phiếu tháng trước (kỳ lương đã khóa): duyệt cuối tháng (1 phiếu không duyệt). Phiếu đã chốt từ trước giữ nguyên.
        $this->event($this->thisMonth->copy()->setTime(10, 0)->min($this->realNow->copy()->subMinutes(30)), fn () => $this->freezeLastMonthSheets(true));

        // Phiếu tháng này: tự tạo đầu tháng (kpi:create-sheets), người chấm đã điền số liệu điền tay cho ~80% phiếu.
        $this->event($this->thisMonth->copy()->setTime(0, 10), fn () => $sheets->ensureSheets($this->thisMonth->month, $this->thisMonth->year));
        $this->event($this->realNow->copy()->subMinutes(50), function () use ($sheets) {
            $sheets->ensureSheets($this->thisMonth->month, $this->thisMonth->year);
            KpiEvaluation::with('user.roles')->where('year', $this->thisMonth->year)->where('month', $this->thisMonth->month)
                ->where('status', KpiEvaluation::STATUS_PENDING)->get()
                ->each(function (KpiEvaluation $evaluation) {
                    if ($evaluation->user && KpiCriterion::roleFor($evaluation->user) !== Roles::ACADEMIC_LEAD
                        && $this->roll($evaluation->user_id, $this->thisMonth->format('Y-m'), 'kpi') < 80) {
                        $this->fillManualValues($evaluation->user, $evaluation, $this->thisMonth);
                    }
                });
        });
    }

    /** Điền số liệu tiêu chí điền tay (và nguồn tỉ lệ chưa có dữ liệu): phần lớn đạt, vài mục trượt bậc. */
    /**
     * Chấm và chốt phiếu KPI tháng trước cho nhân sự đã vào làm trước tháng này (bỏ Trưởng Học thuật — DemoAcademicKpiSeeder chấm
     * riêng); $withRejected: 1 phiếu trợ giảng không duyệt. Phiếu đã chốt từ trước giữ nguyên.
     */
    private function freezeLastMonthSheets(bool $withRejected): void
    {
        $sheets = app(KpiSheetService::class);
        $L = $this->lastMonth;
        $rejected = ! $withRejected;
        $sheets->staffQuery()->with('roles')
            ->where(fn ($q) => $q->whereNull('contract_start_date')->orWhereDate('contract_start_date', '<', $this->thisMonth->toDateString()))
            ->orderBy('id')->get()
            ->each(function (User $staff) use ($sheets, $L, &$rejected) {
                $period = KpiSheetService::periodFor($staff, $L->month, $L->year);
                if ($period['months'] !== 1 || KpiCriterion::roleFor($staff) === Roles::ACADEMIC_LEAD) {
                    return;
                }
                $evaluation = KpiEvaluation::firstOrCreate(
                    ['user_id' => $staff->id, 'month' => $period['month'], 'year' => $period['year']],
                    ['period_months' => 1, 'total_score' => 0, 'status' => KpiEvaluation::STATUS_PENDING]
                );
                if ($evaluation->status !== KpiEvaluation::STATUS_PENDING) {
                    return;
                }
                $this->fillManualValues($staff, $evaluation, $L);
                $sheet = $sheets->sheet($staff, $period['month'], $period['year'], $evaluation->fresh('items'));
                $reject = ! $rejected && $staff->hasRole(Roles::ASSISTANT);
                $rejected = $rejected || $reject;
                foreach ($sheet['lines'] as $line) {
                    KpiEvaluationItem::updateOrCreate(
                        ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $line['criterion']->id],
                        ['actual' => $line['value'] === null ? null : (string) $line['value'], 'score' => $line['level'] ?? 0, 'evidence' => $line['auto'] ? $line['evidence'] : null, 'not_applicable' => $line['na']]
                    );
                }
                $evaluation->update([
                    'evaluator_id' => $this->deciderFor($staff)->id,
                    'total_score' => $sheet['total'],
                    'status' => $reject ? KpiEvaluation::STATUS_REJECTED : KpiEvaluation::STATUS_APPROVED,
                    'reject_reason' => $reject ? 'Thiếu số liệu điểm danh 2 buổi tuần cuối tháng — Học vụ bổ sung rồi gửi lại.' : null,
                    'comment' => $reject ? null : 'Đánh giá KPI tháng '.$L->format('m/Y').'.',
                    'decided_at' => now(),
                ]);
            });
    }

    private function fillManualValues(User $staff, KpiEvaluation $evaluation, Carbon $month, bool $overwrite = false): void
    {
        $role = KpiCriterion::roleFor($staff);
        if (! $role) {
            return;
        }
        $key = $month->format('Y-m');
        $profile = self::KPI_PROFILES[$staff->email] ?? null;
        $filled = $evaluation->items()->where(fn ($q) => $q->whereNotNull('actual')->orWhere('not_applicable', true))
            ->when($overwrite, fn ($q) => $q->where('not_applicable', true))->pluck('kpi_criterion_id')->all();
        foreach (KpiCriterion::forRole($role)->active()->ordered()->get() as $criterion) {
            if (! $criterion->hasRule() || ($criterion->isAuto() && ! $criterion->isRateSource()) || in_array($criterion->id, $filled)) {
                continue;
            }
            $salt = $key.'|'.$criterion->id;
            // Ngưỡng của tiêu chí tỉ lệ: bậc thấp nhất / cao nhất (hoặc mức đủ điểm) — tốt thì vượt bậc cao, kém thì dưới bậc thấp.
            $marks = $criterion->tiers ? array_column($criterion->tiers, 0) : [(float) ($criterion->full_at ?: 100)];
            [$low, $top] = [(int) min($marks), (int) max($marks)];
            $rate = fn (int $min, int $max) => $this->between($staff->id, $salt, 'rate', $min, max($min, $max));
            $value = match (true) {
                ! $criterion->isRate() => match ($profile) {
                    'good' => 0,
                    'bad' => $this->between($staff->id, $salt, 'cnt3', 3, 6),
                    default => $this->roll($staff->id, $salt, 'cnt') < 75 ? 0 : $this->between($staff->id, $salt, 'cnt2', 1, 3),
                },
                // Tỉ lệ càng thấp càng tốt (vd. % lỗi lặp lại): phần lớn thấp.
                $criterion->isLowerBetter() => match ($profile) {
                    'good' => $rate(0, 3),
                    'bad' => $rate(20, 40),
                    default => $rate(0, 15),
                },
                default => match ($profile) {
                    'good' => $rate($top, min(100, $top + 10)),
                    'bad' => $rate((int) round($low * 0.4), (int) round($low * 0.85)),
                    default => $rate((int) round($low * 0.85), min(100, $top + 15)),
                },
            };
            $value = (string) $value;
            KpiEvaluationItem::updateOrCreate(
                ['kpi_evaluation_id' => $evaluation->id, 'kpi_criterion_id' => $criterion->id],
                ['actual' => $value, 'score' => $criterion->levelFor((float) $value) ?? 0]
            );
        }
    }

    // ── Bảng lương kỳ này ──────────────────────────────────────────────────────

    private function closePayroll(): void
    {
        $admin = $this->staff['admin'];
        $this->at($this->realNow->copy()->subMinutes(20));
        $period = PayrollPeriod::where('year', $this->thisMonth->year)->where('month', $this->thisMonth->month)->first();
        if ($period?->isLocked()) {
            return;
        }
        if ($period) {
            $this->asUser($admin, PayrollController::class, 'calculatePeriod', [], ['id' => $period->id]);
        } else {
            $this->asUser($admin, PayrollController::class, 'storePeriod', ['month' => $this->thisMonth->month, 'year' => $this->thisMonth->year]);
            $period = PayrollPeriod::where('year', $this->thisMonth->year)->where('month', $this->thisMonth->month)->firstOrFail();
        }

        // Nhập tay cho phiếu của nhân sự mới / vừa điền hồ sơ: KPI tự do, phụ cấp, thuế TNCN; để 1 phiếu chưa chốt KPI.
        $this->at($this->realNow->copy()->subMinutes(15));
        $skipped = false;
        // Phiếu đã có khoản nhập tay từ trước (DemoPhase3) giữ nguyên.
        PayrollRecord::with('user.roles')->where('payroll_period_id', $period->id)->whereIn('user_id', array_keys($this->touchedProfiles))
            ->whereNull('adjustment_notes')->orderBy('id')->get()
            ->each(function (PayrollRecord $record) use (&$skipped) {
                if ($record->kpi_source === PayrollRecord::KPI_MANUAL && ! $skipped && $record->user?->getRoleNames()->first() === Roles::MANAGER) {
                    $skipped = true;

                    return;
                }
                $this->adjustManual($record, $this->thisMonth);
            });

        $this->at($this->realNow->copy()->subMinutes(10));
        $this->asUser($admin, PayrollController::class, 'calculatePeriod', [], ['id' => $period->id]);
    }

    // ── Tổng kết ───────────────────────────────────────────────────────────────

    /** Admin nhập tay trên phiếu lương: KPI tự do (vai trò không có phiếu KPI), phụ cấp theo vai trò, thuế TNCN. */
    private function adjustManual(PayrollRecord $record, Carbon $month): void
    {
        $role = $record->user?->getRoleNames()->first();
        $manualKpi = $record->kpi_source === PayrollRecord::KPI_MANUAL;
        $base = (float) $record->base_salary;
        $lines = match ($role) {
            Roles::MANAGER => [['kind' => 'earning', 'label' => 'Phụ cấp trách nhiệm', 'amount' => 1000000]],
            Roles::SALES_CONSULTANT => [['kind' => 'earning', 'label' => 'Phụ cấp xăng xe, điện thoại', 'amount' => 300000]],
            Roles::ACADEMIC_STAFF => [['kind' => 'earning', 'label' => 'Gửi xe', 'amount' => 100000]],
            Roles::ASSISTANT => [['kind' => 'earning', 'label' => 'Hỗ trợ sự kiện khai giảng', 'amount' => 150000]],
            default => [],
        };
        $this->asUser($this->staff['admin'], PayrollController::class, 'adjustRecord', array_filter([
            'retention_tier' => $record->retention_tier,
            'foreign_session_pay' => $record->foreign_session_pay,
            'kpi_manual_amount' => $manualKpi ? ($role === Roles::MANAGER ? 1500000 : 600000) : null,
            'tax_deduction' => $base >= 11000000 ? round(($base - 11000000) * 0.05 / 1000) * 1000 + 150000 : null,
            'lines' => $lines,
            'adjustment_notes' => 'Admin nhập tay kỳ '.$month->format('m/Y').'.',
        ], fn ($v) => $v !== null && $v !== []), ['id' => $record->id]);
    }

    // ── Kỳ lương tháng trước đủ mọi vai trò ───────────────────────────────────

    /**
     * Kỳ tháng trước do DemoPhase3 duyệt từ khi Quản lý cơ sở, 1 Sale, GV part-time, GVNN, phần lớn trợ giảng chưa có hồ sơ lương /
     * lịch dạy, nên thiếu phiếu của họ. Bổ sung: lớp K27 dạy cả tháng trước (GV / TA check-in từng buổi, Học vụ duyệt ca, chấm công
     * điện thoại các ngày dạy), mở lại kỳ (đảo đúng các bước của Duyệt), Admin "Đồng bộ & Tính lại", nhập KPI / phụ cấp cho phiếu
     * mới, duyệt lại; từ ngày 10 (lịch trả lương) đánh dấu đã chi trả. Nhân sự vào làm từ tháng này không có phiếu tháng trước.
     * Idempotent theo lớp K27 đầu tiên; kỳ đã chi trả hoặc không phải kỳ demo thì không đụng tới.
     */
    private function completeLastMonth(): void
    {
        if (ClassModel::withTrashed()->where('code', array_key_first(self::LAST_MONTH_CLASSES))->exists()) {
            return;
        }
        $period = PayrollPeriod::where('year', $this->lastMonth->year)->where('month', $this->lastMonth->month)->first();
        if ($period && ($period->status === 'paid' || ($period->isLocked() && ! $period->records()->where('adjustment_notes', 'like', '%demo%')->exists()))) {
            $this->command?->warn('DemoPayrollSeeder: kỳ lương tháng trước đã chi trả hoặc không phải kỳ demo — không bổ sung.');

            return;
        }

        // Giờ thật lúc bắt đầu phần này (sau các seeder trước): tính lại / duyệt kỳ sau mọi thay đổi đã ghi, để "Duyệt" không
        // báo dữ liệu đổi sau lần tính.
        $now = Carbon::now();
        $sessions = $this->setupLastMonthClasses();
        $hadRecord = $period ? $period->records()->pluck('user_id')->all() : [];
        if ($period?->isLocked()) {
            $this->at($now);
            $this->reopenPeriod($period);
        }

        // GV / TA check-in từng buổi (đến sớm 8–25 phút), chấm công điện thoại theo giờ dạy, Học vụ duyệt ca sáng hôm sau; trừ các
        // buổi có sự cố trong LAST_MONTH_TEACHING_ISSUES (đi muộn, quên check-in, vắng).
        $reviewers = ['CG' => $this->staff['academic_cg'], 'BD' => $this->staff['academic_bd'], 'DD' => $this->staff['admin']];
        $days = [];
        $nth = [];
        foreach ($sessions as $session) {
            $date = $session->date->toDateString();
            $start = $session->date->copy()->setTimeFromTimeString($session->start_time->format('H:i'));
            $reviewer = $reviewers[$this->branchCode[$session->branch_id] ?? 'CG'];
            foreach (array_filter([$session->teacher_id, $session->assistant_id]) as $uid) {
                $user = User::find($uid);
                $nth[$uid] = ($nth[$uid] ?? 0) + 1;
                [$issue, $minutes] = self::LAST_MONTH_TEACHING_ISSUES[$user->email][$nth[$uid]] ?? [null, null];
                if ($issue === 'absent') {
                    continue;
                }
                $arrive = $issue === 'late' ? $start->copy()->addMinutes($minutes) : $start->copy()->subMinutes($this->between($uid, $date, 'k27', 8, 25));
                if ($issue === 'forgot') {
                    $this->event($start->copy()->setTime(21, 15), fn () => $this->asUser($reviewer, PayrollController::class, 'storeTimesheet', [
                        'user_id' => $uid, 'class_id' => $session->class_id, 'teaching_date' => $date,
                        'time_in' => $session->start_time->format('H:i'), 'time_out' => $session->end_time->format('H:i'), 'type' => 'regular',
                        'notes' => 'GV quên check-in — đối chiếu sổ điểm danh và camera lớp, xác nhận có dạy.',
                    ]));
                } else {
                    $this->event($arrive->copy()->addMinute(), fn () => $this->asUser($user, TeacherPortalController::class, 'checkin', ['session_ids' => [$session->id]]));
                }
                $days[$uid][$date] ??= [$arrive, $session->date->copy()->setTimeFromTimeString($session->end_time->format('H:i'))->addMinutes(10)];
            }
            $this->event($session->date->copy()->addDay()->setTime(7, 45), function () use ($session, $reviewer) {
                TeacherTimesheet::where('class_session_id', $session->id)->where('status', 'pending_review')->orderBy('id')->get()
                    ->each(fn (TeacherTimesheet $t) => $this->asUser($reviewer, PayrollController::class, 'reviewTimesheet', ['decision' => 'valid'], ['id' => $t->id]));
            });
        }
        // TA đề nghị tính công buổi hỗ trợ ngoài lịch (thứ 6, lớp không có buổi) → Học vụ từ chối.
        $ta = $this->user('ta.thu@menglish.edu.vn');
        $class = ClassModel::where('code', 'DEMO-CG-IE27')->first();
        $friday = $this->lastMonth->copy()->addDays(9)->next(Carbon::FRIDAY);
        if ($ta && $class) {
            $this->event($friday->copy()->setTime(20, 0), fn () => $this->asUser($this->staff['academic_cg'], PayrollController::class, 'storeTimesheet', [
                'user_id' => $ta->id, 'class_id' => $class->id, 'teaching_date' => $friday->toDateString(),
                'time_in' => '17:00', 'time_out' => '19:00', 'type' => 'workshop', 'notes' => 'TA đề nghị tính công buổi trang trí lớp chuẩn bị Halloween.',
            ]));
            $this->event($friday->copy()->addDay()->setTime(8, 0), function () use ($ta, $friday) {
                TeacherTimesheet::where('user_id', $ta->id)->whereDate('teaching_date', $friday->toDateString())->where('status', 'pending_review')->get()
                    ->each(fn (TeacherTimesheet $t) => $this->asUser($this->staff['academic_cg'], PayrollController::class, 'reviewTimesheet', [
                        'decision' => 'invalid', 'rejection_reason' => 'Không có buổi học trên lịch lớp ngày này — việc trang trí lớp không tính buổi dạy.',
                    ], ['id' => $t->id]));
            });
        }
        foreach ($days as $uid => $byDate) {
            $user = User::find($uid);
            foreach ($byDate as $date => [$in, $out]) {
                $this->event($out, fn () => $this->writePast($user, Carbon::parse($date), $in, $out));
            }
        }
        $this->planViolations();
        $this->runEvents();
        ClassModel::whereIn('code', array_keys(self::LAST_MONTH_CLASSES))->update(['status' => 'completed']);

        // KPI mọi vai trò: bộ mẫu cho vai trò còn thiếu (Quản lý cơ sở), chốt phiếu tháng trước còn thiếu; phiếu tháng này của
        // người tốt / kém điền lại theo mức của họ, phiếu còn trống thì điền.
        $this->setupKpiCriteria();
        $this->at($this->thisMonth->copy()->setTime(10, 0)->min($now->copy()->subMinutes(30)));
        $this->freezeLastMonthSheets(false);
        $this->at($now);
        app(KpiSheetService::class)->ensureSheets($this->thisMonth->month, $this->thisMonth->year);
        KpiEvaluation::with('user.roles')->where('year', $this->thisMonth->year)->where('month', $this->thisMonth->month)
            ->where('status', KpiEvaluation::STATUS_PENDING)->get()
            ->each(function (KpiEvaluation $evaluation) {
                if ($evaluation->user && KpiCriterion::roleFor($evaluation->user) !== Roles::ACADEMIC_LEAD
                    && (isset(self::KPI_PROFILES[$evaluation->user->email]) || ($evaluation->user->hasRole(Roles::MANAGER)
                        && $this->roll($evaluation->user_id, $this->thisMonth->format('Y-m'), 'kpi') < 80 && ! $evaluation->items()->whereNotNull('actual')->exists()))) {
                    $this->fillManualValues($evaluation->user, $evaluation, $this->thisMonth, isset(self::KPI_PROFILES[$evaluation->user->email]));
                }
            });

        // Admin tính lại kỳ (hôm nay), nhập tay phiếu mới, tính lại, duyệt; từ ngày 10 ghi nhận chi trả.
        $admin = $this->staff['admin'];
        $this->at($now);
        if (! $period) {
            $this->asUser($admin, PayrollController::class, 'storePeriod', ['month' => $this->lastMonth->month, 'year' => $this->lastMonth->year]);
            $period = PayrollPeriod::where('year', $this->lastMonth->year)->where('month', $this->lastMonth->month)->firstOrFail();
        }
        $this->calculateExcludingNewStaff($period);
        $this->at($now);
        $period->records()->with('user.roles')->whereNotIn('user_id', $hadRecord)->whereNull('adjustment_notes')->orderBy('id')->get()
            ->each(fn (PayrollRecord $record) => $this->adjustManual($record, $this->lastMonth));
        $this->at($now);
        $this->calculateExcludingNewStaff($period);
        $this->at($now);
        // Nhân sự sẵn có trên hệ thống (ngoài demo) chưa chốt KPI… thì không duyệt được: để kỳ "Đang soát", không chốt hộ.
        try {
            $this->asUser($admin, PayrollController::class, 'approvePeriod', [], ['id' => $period->id]);
        } catch (RuntimeException $e) {
            $this->command?->warn("{$e->getMessage()} → kỳ {$period->code} để ở \"Đang soát\".");
        }
        // Biên bản tháng trước đã trừ lương → nhân sự khắc phục; kỳ tháng này tính lại để trừ biên bản quá hạn nộp.
        foreach ($this->deductedPenalties as $penalty) {
            if ($penalty->fresh()->status === 'deducted') {
                $this->asUser($this->deciderFor($penalty->user), PenaltyController::class, 'remedyPenalty', ['remedy_note' => 'Đã khắc phục, tháng này không tái phạm.'], ['id' => $penalty->id]);
            }
        }
        $current = PayrollPeriod::where('year', $this->thisMonth->year)->where('month', $this->thisMonth->month)->first();
        if ($current && ! $current->isLocked()) {
            $this->asUser($admin, PayrollController::class, 'calculatePeriod', [], ['id' => $current->id]);
        }
        if ($period->refresh()->status === 'approved' && $now->day >= 10) {
            $this->at($now);
            $this->asUser($admin, PayrollController::class, 'markPaid', [], ['id' => $period->id]);
        }
        $this->at($this->realNow);
    }

    /**
     * Lập biên bản VIOLATIONS qua đúng luồng: Học vụ / Quản lý lập (kèm ảnh bằng chứng) ngay sau vi phạm, nhân sự giải trình tối
     * đó, người chốt theo loại lỗi (Học thuật: lỗi chuyên môn; Quản lý cơ sở: lỗi vận hành; Admin khi người vi phạm là Quản lý /
     * Học thuật) chốt sáng hôm sau, nộp phạt trực tiếp trong hạn hoặc để quá hạn cho bảng lương trừ.
     *
     * Biên bản tháng trước sẽ trừ lương được ghi vào $deductedPenalties (khắc phục sau khi duyệt kỳ).
     */
    private function planViolations(): void
    {
        $admin = $this->staff['admin'];
        $lead = $this->user('academiclead@menglish.edu.vn');
        foreach (self::VIOLATIONS as [$email, $category, $type, $note, [$unit, $value], $flow, $amount, $explanation]) {
            $user = $this->user($email);
            if (! $user) {
                continue;
            }
            $at = ($unit === 'L' ? $this->lastMonth->copy()->addDays($value - 1) : $this->realNow->copy()->startOfDay()->subDays($value))->setTime(17, 30);
            $code = $this->branchCode[$user->branch_id] ?? null;
            $academic = $user->hasRole(Roles::ACADEMIC_STAFF) || $user->hasRole(Roles::MANAGER) || $user->hasRole(Roles::ACADEMIC_LEAD);
            $reporter = match (true) {
                $academic => $admin,
                $code === 'CG' => $this->staff['academic_cg'],
                $code === 'BD' => $this->staff['academic_bd'],
                $code === 'DD' && $at->gte($this->thisMonth) => $this->user('giaovu.dd@menglish.edu.vn') ?? $admin,
                default => $admin,
            };
            $decider = $academic ? $admin : ($category === 'academic' ? $lead ?? $admin : $this->deciderFor($user));
            $find = fn () => Penalty::where('user_id', $user->id)->where('violation_type', $type)->whereDate('violation_date', $at->toDateString())->latest('id')->first();

            $this->event($at->copy()->addHours(2), fn () => $this->asUser($reporter, PenaltyController::class, 'storePenalty', [
                'user_id' => $user->id, 'error_category' => $category, 'violation_type' => $type, 'violation_at' => $at->format('Y-m-d H:i:s'),
                'amount' => $amount, 'notes' => $note, 'evidence' => $this->photoFile($user, $at, 'bang-chung'),
            ]));
            if ($explanation && $flow !== 'pending') {
                $this->event($at->copy()->addHours(4), fn () => $this->asUser($user, PenaltyController::class, 'explain', ['explanation' => $explanation], ['id' => $find()->id]));
            }
            $decideAt = $at->copy()->addDay()->setTime(10, 0);
            match ($flow) {
                'confirmed' => $this->event($decideAt, fn () => $this->asUser($decider, PenaltyController::class, 'confirmPenalty', ['decision' => 'error', 'decision_note' => 'Xác nhận có lỗi, nhắc nhở lần đầu — chưa phạt tiền.'], ['id' => $find()->id])),
                'resolved' => $this->event($decideAt, fn () => $this->asUser($admin, PenaltyController::class, 'resolvePenalty', [], ['id' => $find()->id])),
                'cancelled' => $this->event($at->copy()->addHours(3), fn () => $this->asUser($reporter, PenaltyController::class, 'cancelPenalty', [], ['id' => $find()->id])),
                'fined', 'paid', 'remedied', 'deducted' => $this->event($decideAt, fn () => $this->asUser($decider, PenaltyController::class, 'confirmPenalty', ['decision' => 'fine', 'amount' => $amount, 'decision_note' => 'Phạt theo quy chế, nộp trong hạn hoặc trừ vào lương.'], ['id' => $find()->id])),
                default => null,
            };
            if (in_array($flow, ['paid', 'remedied'], true)) {
                $payer = $decider->can('violation.mark_paid') ? $decider : $admin;
                $this->event($decideAt->copy()->addDay()->setTime(9, 0), fn () => $this->asUser($payer, PenaltyController::class, 'markPaidPenalty', [], ['id' => $find()->id]));
            }
            if ($flow === 'remedied') {
                $this->event($decideAt->copy()->addDays(3), fn () => $this->asUser($payer, PenaltyController::class, 'remedyPenalty', ['remedy_note' => 'Đã khắc phục, không tái phạm.'], ['id' => $find()->id]));
            }
            if ($flow === 'deducted' && $unit === 'L') {
                $this->event($decideAt->copy()->addMinute(), function () use ($find) {
                    $this->deductedPenalties[] = $find();
                });
            }
        }
    }

    /** @return Collection<int, ClassSession> buổi đã dạy của các lớp K27 */
    private function setupLastMonthClasses()
    {
        $this->at($this->lastMonth->copy()->subDays(5)->setTime(9, 0));
        $start = $this->lastMonth->copy();
        $end = $this->thisMonth->copy()->subDay();
        $days = ['', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
        $sessions = collect();

        foreach (self::LAST_MONTH_CLASSES as $code => [$name, $branchCode, $courseKeyword, $teacherEmail, $assistantEmails, $room, $schedule]) {
            $branch = $this->branches[$branchCode] ?? null;
            $teacher = $this->user($teacherEmail);
            $assistants = collect($assistantEmails)->map(fn ($e) => $this->user($e))->filter()->values();
            if (! $branch || ! $teacher) {
                continue;
            }
            $course = Course::where('name', 'like', "%{$courseKeyword}%")->first() ?? Course::query()->orderBy('id')->first();
            $class = ClassModel::create([
                'code' => $code, 'name' => $name, 'course_id' => $course?->id, 'branch_id' => $branch->id,
                'program' => $course?->name ? trim(str_replace('#', '', $course->name)) : null, 'level' => 'K27',
                'teacher_id' => $teacher->id, 'assistant_id' => $assistants->first()?->id, 'room' => $room,
                'schedule_text' => collect($schedule)->map(fn ($s) => "{$days[$s[0]]} {$s[1]}-{$s[2]}")->implode('; '),
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'max_capacity' => 14, 'min_students' => 6, 'tuition_fee' => $course?->tuition_fee,
                'status' => 'active', 'notes' => 'Lớp K27 đã kết thúc tháng trước (dữ liệu mẫu lương '.self::MARKER.').',
            ]);

            $n = 0;
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                foreach ($schedule as [$weekday, $from, $to, $shift]) {
                    if ($day->dayOfWeekIso !== $weekday || $this->isHoliday($day)) {
                        continue;
                    }
                    $sessions->push(ClassSession::create([
                        'class_id' => $class->id, 'branch_id' => $branch->id, 'date' => $day->toDateString(),
                        'shift_name' => $shift, 'type' => ClassSession::TYPE_REGULAR, 'start_time' => $from, 'end_time' => $to,
                        'room' => $room, 'teacher_id' => $teacher->id,
                        'assistant_id' => $assistants->isEmpty() ? null : $assistants[$n % $assistants->count()]->id,
                        'status' => 'completed',
                    ]));
                    $n++;
                }
            }
        }

        return $sessions;
    }

    /** Đảo đúng các bước của "Duyệt bảng lương" (PayrollController::approvePeriod) để kỳ demo tính lại được. */
    private function reopenPeriod(PayrollPeriod $period): void
    {
        $recordIds = $period->records()->pluck('id');
        Penalty::whereIn('payroll_record_id', $recordIds)->where('status', 'deducted')->update(['status' => 'fined']);
        CommissionAdjustment::whereIn('payroll_record_id', $recordIds)->update(['settled_at' => null]);
        CommissionItem::whereIn('payroll_record_id', $recordIds)->where('status', CommissionItem::STATUS_PAID)
            ->update(['settled_at' => null, 'status' => CommissionItem::STATUS_PAYABLE]);
        AdminNotification::where('type', 'payroll_approved')->where('data', 'like', '%"payroll_period_id":'.$period->id.',%')->delete();
        $period->records()->update(['status' => 'pending']);
        $period->update(['status' => 'reviewing']);
    }

    /**
     * "Đồng bộ & Tính lại" kỳ tháng trước. Phép tính lấy mọi nhân sự đang hoạt động, kể cả người vào làm từ tháng này (chưa có
     * hồ sơ tháng trước) — tạm loại họ khỏi lần tính để không sinh phiếu lương tháng trước cho người chưa vào làm.
     */
    private function calculateExcludingNewStaff(PayrollPeriod $period): void
    {
        $newcomers = User::where('is_active', true)->whereDate('contract_start_date', '>', $period->end_date->toDateString())->pluck('id');
        User::whereKey($newcomers)->update(['is_active' => false]);
        try {
            $this->asUser($this->staff['admin'], PayrollController::class, 'calculatePeriod', [], ['id' => $period->id]);
        } finally {
            User::whereKey($newcomers)->update(['is_active' => true]);
        }
    }

    private function printSummary(): void
    {
        if (! $this->command) {
            return;
        }
        $month = now()->startOfMonth();
        $last = $month->copy()->subMonthNoOverflow();
        $countBy = fn ($query, string $column) => $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k')
            ->map(fn ($c, $k) => "{$k}: {$c}")->implode(', ');
        $period = PayrollPeriod::where('year', $month->year)->where('month', $month->month)->first();
        $classIds = ClassModel::whereIn('code', array_keys(self::CLASSES))->pluck('id');

        $this->command->table(['Lương (Phase 5)', 'Số dòng'], [
            ['Cơ sở đã cài chấm công', Branch::whereNotNull('latitude')->pluck('code')->implode(', ')],
            ['Chấm công điện thoại tháng trước / tháng này', StaffAttendance::whereBetween('work_date', [$last->toDateString(), $month->copy()->subDay()->toDateString()])->count()
                .' / '.StaffAttendance::where('work_date', '>=', $month->toDateString())->count()],
            ['Chấm công tháng này: đi muộn / muộn có phép / chưa chấm ra', StaffAttendance::where('work_date', '>=', $month->toDateString())->where('late_minutes', '>', 0)->where('late_excused', false)->count()
                .' / '.StaffAttendance::where('work_date', '>=', $month->toDateString())->where('late_excused', true)->where('late_minutes', '>', 0)->count()
                .' / '.StaffAttendance::where('work_date', '>=', $month->toDateString())->whereNotNull('check_in_at')->whereNull('check_out_at')->count()],
            ['Đơn xin duyệt theo trạng thái', $countBy(StaffAttendanceRequest::query(), 'status')],
            ['Biên bản đi muộn (tự động) theo trạng thái', $countBy(Penalty::query()->where('notes', 'like', 'Tự động từ chấm công%'), 'status')],
            ['Lớp K28 / buổi / ca dạy theo trạng thái', $classIds->count().' / '.ClassSession::whereIn('class_id', $classIds)->count().' / '.$countBy(TeacherTimesheet::query()->whereIn('class_id', $classIds), 'status')],
            ['Vai trò có tiêu chí KPI', KpiCriterion::active()->distinct()->pluck('role')->implode(', ')],
            ['Phiếu KPI tháng trước', $countBy(KpiEvaluation::query()->where('year', $last->year)->where('month', $last->month), 'status')],
            ['Phiếu KPI tháng này', $countBy(KpiEvaluation::query()->where('year', $month->year)->where('month', $month->month), 'status')],
            ['Kỳ lương tháng này', $period ? "{$period->code} · {$period->status} · ".$period->records()->count().' phiếu · '.number_format((float) $period->total_amount, 0, ',', '.').'đ' : '—'],
        ]);
    }
}

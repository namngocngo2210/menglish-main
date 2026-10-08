<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Bảng A của file KPI Học thuật: tỷ lệ đạt → % điểm tối đa (≥ 95: 100, 90: 90, 80: 75, 70: 60, 60: 30, dưới 60: 0). */
    private const TABLE_A = [[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]];

    /**
     * Bộ KPI Học thuật (Head Academic) theo file "KPI_Hoc_Thuat_ME_V11_THEO_THANG 2026" (chủ dự án 08/10/2026): 5 nhóm,
     * 11 chỉ tiêu (mục 10 tách 10a đi muộn / 10b nghỉ không phép), tổng 100 điểm, chấm theo tháng. weight = điểm tối đa.
     * allow_na: tháng không phát sinh đơn vị đánh giá → "Không phát sinh", bỏ khỏi cả tử số và mẫu số.
     * Mỗi dòng: [nhóm, tên, điểm, cách đo, đơn vị, bậc, allow_na, knockout, nguồn tự động, mô tả].
     */
    private const ACADEMIC_LEAD = [
        ['Chương trình & học liệu', 'Deliverable đúng hạn theo master plan (Program / Book / Slide / Test / Speaking)', 13, 'rate', 'phan_tram', self::TABLE_A, true, false, 'academic_deliverable_on_time',
            'Mốc dự án học thuật đến hạn trong tháng (người phụ trách hoặc chủ dự án): đúng hạn = 1; trễ 1–3 ngày = 0,5; 4–7 ngày = 0,25; từ 8 ngày = 0. % = trung bình hệ số, quy đổi theo Bảng A. Tháng ít hơn 3 mốc thì gộp thêm tháng trước; không có mốc nào → Không phát sinh.'],
        ['Chương trình & học liệu', 'Chất lượng deliverable (đạt ngay vòng duyệt đầu)', 10, 'rate', 'phan_tram', self::TABLE_A, true, false, null,
            '% deliverable đạt ngay vòng duyệt đầu (chỉ sửa nhỏ), người duyệt độc lập với tác giả. Có lỗi Major phát hiện sau phát hành trong tháng → người chấm điền tối đa mức cho 50% điểm.'],
        ['Chương trình & học liệu', 'Kiểm soát tiến độ thực hiện chương trình', 9, 'rate', 'phan_tram', self::TABLE_A, true, false, null,
            '% lớp đang học bám đúng tiến độ (lệch ≤ 1 buổi so với kế hoạch), sau loại trừ có mã lý do (tài liệu trễ, GV nghỉ/đổi GV, lớp tạm dừng, sự kiện trung tâm).'],
        ['Đào tạo & rà soát giảng dạy GV', 'Đào tạo & triển khai thay đổi học thuật cho GV đúng kế hoạch', 9, 'rate', 'phan_tram', self::TABLE_A, true, false, null,
            'Buổi/module đào tạo trong kế hoạch tháng (GV mới, định kỳ, quy trình học thuật, chương trình mới/đổi mục tiêu): đúng kế hoạch = 1; bù trong ≤ 7 ngày = 0,5; không thực hiện = 0. Thay đổi phát sinh: xong trước ngày áp dụng ≥ 3 ngày = 1, sát ngày = 0,5, sau ngày áp dụng = 0. GV có đi dự hay không tính ở KPI GV.'],
        ['Đào tạo & rà soát giảng dạy GV', 'Xử lý phản ánh về GV đúng hạn', 10, 'rate', 'phan_tram', self::TABLE_A, true, false, null,
            '% phản ánh về GV trong tháng (báo cáo dự giờ Học vụ, phản ánh chất lượng, HS/PH, rà soát hồ sơ lớp) được xử lý và phản hồi đúng hạn: nghiêm trọng ≤ 1 ngày làm việc, nhẹ ≤ 3 ngày, báo cáo dự giờ ≤ 5 ngày. Chỉ đo tốc độ xử lý, không chấm số lỗi của GV.'],
        ['Chất lượng đầu ra & kiểm soát lỗi GV', 'Chất lượng đầu ra học sinh (cuốn chiếu 3 tháng)', 15, 'rate', 'phan_tram', [[90, 100], [85, 80], [80, 60], [70, 30]], true, false, 'student_pass_rate',
            '% bài Big Test / mini test đạt chuẩn (≥ 7 điểm thang 10) của các lớp trong 3 tháng gần nhất: ≥ 90% đủ điểm, 85–89% 80%, 80–84% 60%, 70–79% 30%, dưới 70% là 0. Điều kiện chặn "0% cuối quý 2 quý liên tiếp → tối đa loại C" người duyệt tự áp.'],
        ['Chất lượng đầu ra & kiểm soát lỗi GV', 'Lỗi GV lặp lại sau khi đã xử lý', 4, 'rate_down', 'phan_tram', [[0, 100], [10, 75], [25, 50]], true, false, null,
            '% lỗi lặp lại (cùng GV, cùng loại, trong 60 ngày sau xử lý) trên số lỗi đã xử lý, cuốn chiếu 3 tháng: 0% đủ điểm, ≤ 10% 75%, ≤ 25% 50%, trên 25% là 0. Không có lỗi nào để xét → Không phát sinh.'],
        ['Vận hành học thuật', 'Task đúng hạn (task thường)', 7, 'rate', 'phan_tram', self::TABLE_A, true, false, 'task_on_time',
            'Việc được giao (Giao việc) có hạn trong tháng: đúng hạn = 1; trễ ≤ 4 giờ = 0,5; 4–24 giờ = 0,25; quá 24 giờ = 0. % → Bảng A. Tháng ít hơn 3 việc thì gộp thêm tháng trước.'],
        ['Vận hành học thuật', 'Order học thuật & phân bổ chương trình/học liệu đúng SLA', 13, 'rate', 'phan_tram', self::TABLE_A, true, false, 'academic_order_on_time',
            'Order "Học liệu học thuật" có ngày sử dụng trong tháng, theo thời gian còn lại trước giờ dùng (giờ bắt đầu buổi học của lớp): ≥ 24 giờ = 1; 12–24 giờ = 0,5; 6–12 giờ = 0,25; dưới 6 giờ = 0. Order tạo sát hơn 24 giờ trước giờ dùng là lỗi bên yêu cầu, không tính. Có order xong sau giờ dùng → mục này tối đa 50% và tháng không xếp loại A.'],
        ['Kỷ luật & phối hợp', 'Đi muộn (quá 10 phút)', 4, 'count', 'lan', [[0, 100], [1, 70], [2, 40], [3, 15]], false, false, 'staff_late',
            'Số ngày chấm công đi muộn quá 10 phút (không có đơn được duyệt): 0 lần đủ điểm, 1 lần 70%, 2 lần 40%, 3 lần 15%, từ 4 lần là 0. Phạt tiền 30.000đ/lần tính riêng qua biên bản.'],
        ['Kỷ luật & phối hợp', 'Nghỉ không phép', 2, 'count', 'ngay', [[0, 100]], false, true, null,
            'Số ngày nghỉ không có đơn được duyệt. Có từ 1 ngày: mất toàn bộ KPI tháng (như GV) và phạt theo quy chế riêng.'],
        ['Kỷ luật & phối hợp', 'Họp bắt buộc & phối hợp', 4, 'count', 'buoi', [[0, 100], [1, 60], [2, 20]], false, false, null,
            'Số buổi họp bắt buộc vắng không phép trong tháng: 0 đủ điểm, 1 buổi 60%, 2 buổi 20%, từ 3 buổi là 0. Đi muộn họp tính ở mục đi muộn.'],
    ];

    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->boolean('allow_na')->default(false)->after('knockout');
        });
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->boolean('not_applicable')->default(false)->after('critical_error');
        });

        if (DB::table('kpi_criteria')->where('role', 'academic_lead')->whereNull('deleted_at')->exists()) {
            return;
        }
        $now = now();
        foreach (self::ACADEMIC_LEAD as $i => [$group, $name, $weight, $measure, $unit, $tiers, $allowNa, $knockout, $source, $description]) {
            DB::table('kpi_criteria')->insert([
                'role' => 'academic_lead',
                'group_name' => $group,
                'name' => $name,
                'weight' => $weight,
                'measure' => $measure,
                'unit' => $unit,
                'tiers' => json_encode($tiers),
                'linear' => false,
                'full_at' => null,
                'per_month' => false,
                'knockout' => $knockout,
                'allow_na' => $allowNa,
                'auto_source' => $source,
                'description' => $description,
                'is_active' => true,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('kpi_criteria')->where('role', 'academic_lead')->delete();
        Schema::table('kpi_evaluation_items', fn (Blueprint $table) => $table->dropColumn('not_applicable'));
        Schema::table('kpi_criteria', fn (Blueprint $table) => $table->dropColumn('allow_na'));
    }
};

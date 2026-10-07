<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bộ KPI GV part-time theo file "KPI_GV_PartTime_BangTrongSo" (chủ dự án 07/10/2026): 6 nhóm / 15 chỉ tiêu, tổng 100 điểm,
     * chấm theo THÁNG (chủ dự án chọn phiếu tháng; Admin đổi được sang quý ở Tiêu chí KPI). weight = điểm tối đa. measure: count = đếm số lần (tiers = [số lần tối đa, % điểm], vượt bậc cuối = 0),
     * rate = tỉ lệ % (tiers = [từ %, % điểm] hoặc linear = điểm theo tỉ lệ, đủ điểm khi đạt full_at %).
     * Nghỉ dạy không phép: điều kiện loại trừ (weight 0, knockout) — có từ 1 buổi trong kỳ là mất toàn bộ KPI kỳ.
     */
    private const PARTTIME = [
        ['N1', 'Tuân thủ & Chuyên cần', 'Nghỉ dạy không phép', 0, 'count', 'buoi', [[0, 100]], false, null, false, true, 'teacher_absent_unexcused',
            'Buổi dạy trong lịch mà GV không có giờ dạy (check-in) và không có đơn nghỉ được duyệt. Có từ 1 buổi trong tháng là mất toàn bộ KPI tháng; ngoài ra xử lý theo bảng phạt GV.'],
        ['N1', 'Tuân thủ & Chuyên cần', 'Chuyên cần (nghỉ có phép)', 7, 'count', 'lan', [[1, 100], [2, 50]], false, null, true, false, 'teacher_leave',
            'Số ngày nghỉ dạy có đơn được duyệt trong tháng: 1 lần đủ 7 điểm, lần 2 còn 3,5 điểm, từ lần 3 là 0.'],
        ['N1', 'Tuân thủ & Chuyên cần', 'Đi muộn giờ dạy', 8, 'count', 'lan', [[1, 100], [2, 62.5], [3, 25]], false, null, true, false, 'teacher_late',
            'Số buổi check-in muộn (mọi số phút) trong tháng: 1 lần đủ 8 điểm, lần 2 còn 5, lần 3 còn 2, từ lần 4 là 0. Phạt tiền đi muộn tính riêng ngoài KPI.'],
        ['N2', 'Chất lượng học tập', 'Speaking completion (dự án speaking đầu ra)', 7, 'rate', 'phan_tram', null, true, 100, false, false, null,
            'Số speaking đã hoàn thành ÷ số speaking phải có (%). Điểm theo tỉ lệ.'],
        ['N2', 'Chất lượng học tập', 'Kết quả Test (điểm + tỷ lệ đạt chuẩn)', 8, 'rate', 'phan_tram', null, true, 100, false, false, 'test_result',
            'Điểm TB quy về thang 10 × 50% + tỷ lệ học sinh đạt chuẩn (≥ 7 điểm) × 50%, từ Big Test và mini test của các lớp trong tháng.'],
        ['N2', 'Chất lượng học tập', 'Tăng trưởng / tiến bộ học tập của học sinh', 5, 'rate', 'phan_tram', null, true, 10, false, false, 'test_progress',
            '% điểm test tăng = (điểm TB lần test cuối − lần đầu trong tháng) ÷ lần đầu. Đạt mốc (mặc định 10%) là đủ điểm, ≤ 0% là 0, ở giữa theo tỉ lệ.'],
        ['N2', 'Chất lượng học tập', 'Tỷ lệ chuyên cần học sinh của lớp', 5, 'rate', 'phan_tram', [[90, 100], [80, 50]], false, null, false, false, 'class_attendance_rate',
            'Lượt có mặt + đi muộn ÷ tổng lượt điểm danh của các lớp: ≥ 90% đủ điểm, 80–89% một nửa, dưới 80% là 0.'],
        ['N2', 'Chất lượng học tập', 'Tỷ lệ hoàn thành bài tập về nhà (BTVN)', 5, 'rate', 'phan_tram', [[95, 100], [90, 80], [85, 60]], false, null, false, false, 'homework_rate',
            'Lượt học sinh nộp bài trong hạn ÷ (số bài giao × sĩ số): ≥ 95% đủ 5 điểm, ≥ 90% được 4, ≥ 85% được 3, dưới 85% là 0.'],
        ['N2', 'Chất lượng học tập', 'Theo dõi & cảnh báo HS nghỉ / bất thường', 5, 'rate', 'phan_tram', null, true, 100, false, false, null,
            'Số case đã báo đúng hạn ÷ số case cần báo (%), gồm cả trường hợp bất thường của học sinh. Điểm theo tỉ lệ.'],
        ['N3', 'Chất lượng giảng dạy', 'Hoàn thành mục tiêu đầu ra Lesson/Unit (Big Test)', 5, 'rate', 'phan_tram', null, true, 100, false, false, null,
            'Số học sinh đạt mục tiêu ÷ số học sinh phải đạt (%), theo lần đo gần nhất; mục tiêu đặt riêng theo từng lớp / chương trình.'],
        ['N3', 'Chất lượng giảng dạy', 'Dự giờ / quan sát lớp (≥ 2 lần/quý)', 10, 'rate', 'phan_tram', null, true, 100, false, false, null,
            'Điểm trung bình các lần dự giờ trong tháng (thang 100); tháng không có dự giờ lấy điểm lần gần nhất. File yêu cầu ≥ 2 lần/quý. Thang chấm dự giờ chính thức chờ chủ dự án gửi.'],
        ['N4', 'Báo cáo & Phối hợp', 'Báo cáo tháng đúng hạn (trước 12h ngày mùng 2)', 5, 'rate', 'phan_tram', null, true, 100, false, false, 'monthly_report_on_time',
            'Số tháng nộp báo cáo tháng trước 12h ngày mùng 2 tháng sau (tháng có nộp đúng hạn = 100%).'],
        ['N4', 'Báo cáo & Phối hợp', 'Thông báo kịp thời vấn đề lớp (nghỉ / đổi ca / case HS)', 5, 'count', 'lan', [[0, 100], [1, 50]], false, null, false, false, null,
            'Số lần không thông báo kịp thời trong tháng: mỗi lần trừ 2,5 điểm (0 lần đủ 5 điểm, 1 lần còn 2,5, từ 2 lần là 0).'],
        ['N5', 'Trách nhiệm với HS / Phụ huynh', 'Tái tục: học sinh không tái tục do lỗi GV', 10, 'count', 'hoc_vien', [[1, 100], [2, 50]], false, null, false, false, null,
            'Số học sinh không tái tục đã xác minh do lỗi GV (Học vụ + Admin xác minh): ≤ 1 đủ 10 điểm, 2 học sinh được 5, từ 3 là 0. Học sinh nghỉ vì lý do cá nhân / gia đình / học phí không tính.'],
        ['N5', 'Trách nhiệm với HS / Phụ huynh', 'Feedback phụ huynh đã xác minh là lỗi GV', 10, 'count', 'lan', [[0, 100], [1, 80], [2, 60], [3, 40], [4, 20]], false, null, false, false, null,
            'Số feedback gắn nhãn "Lỗi GV" trong tháng (khảo sát, gọi điện, Zalo, thiếu bài tập): 0 đủ 10 điểm, mỗi feedback trừ 2 điểm, thấp nhất 0.'],
        ['N6', 'Đào tạo / Họp / Sự kiện', 'Tham gia đầy đủ đào tạo / họp / sự kiện bắt buộc', 5, 'count', 'buoi', [[0, 100]], false, null, false, false, null,
            'Số buổi bắt buộc được tổ chức trong tháng mà GV không tham gia: 0 buổi đủ 5 điểm, vắng là 0.'],
    ];

    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('measure', 10)->default('count')->after('unit');
            $table->json('tiers')->nullable()->after('measure');
            $table->boolean('linear')->default(false)->after('tiers');
            $table->decimal('full_at', 6, 2)->nullable()->after('linear');
            $table->boolean('per_month')->default(false)->after('full_at');
            $table->boolean('knockout')->default(false)->after('per_month');
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_months')->default(1)->after('year');
        });

        if (DB::table('kpi_criteria')->where('role', 'teacher_parttime')->whereNull('deleted_at')->exists()) {
            return;
        }
        $now = now();
        foreach (self::PARTTIME as $i => [$code, $group, $name, $weight, $measure, $unit, $tiers, $linear, $fullAt, $perMonth, $knockout, $source, $description]) {
            DB::table('kpi_criteria')->insert([
                'role' => 'teacher_parttime',
                'group_name' => $group,
                'name' => $name,
                'weight' => $weight,
                'measure' => $measure,
                'unit' => $unit,
                'tiers' => $tiers === null ? null : json_encode($tiers),
                'linear' => $linear,
                'full_at' => $fullAt,
                'per_month' => $perMonth,
                'knockout' => $knockout,
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
        DB::table('kpi_criteria')->where('role', 'teacher_parttime')->delete();
        Schema::table('kpi_evaluations', fn (Blueprint $table) => $table->dropColumn('period_months'));
        Schema::table('kpi_criteria', fn (Blueprint $table) => $table->dropColumn(['measure', 'tiers', 'linear', 'full_at', 'per_month', 'knockout']));
    }
};

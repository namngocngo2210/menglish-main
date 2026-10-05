<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Bộ mặc định gốc (mockup); bộ hiện hành do migration 2026_10_30_100000 thay. */
    private const LEGACY_ITEMS = [
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.1', 'name' => 'Nhắc học phí', 'weight' => 10, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => '100% HV đến hạn được nhắc', 'description' => null],
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.2', 'name' => 'Thu học phí', 'weight' => 15, 'threshold_full' => '95%', 'threshold_half' => '70%', 'target' => '≥ 95% học phí đến hạn được thu', 'description' => null],
        ['group_name' => 'Chăm sóc học viên', 'code' => '1.3', 'name' => 'Hỗ trợ học viên yếu', 'weight' => 5, 'threshold_full' => '10 HV', 'threshold_half' => '5 HV', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.1', 'name' => 'Tỉ lệ hoàn thành bài tập', 'weight' => 7.5, 'threshold_full' => '90%', 'threshold_half' => '75%', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.2', 'name' => 'Điểm danh đầy đủ', 'weight' => 5, 'threshold_full' => '95%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.3', 'name' => 'Đánh giá từ học viên', 'weight' => 7.5, 'threshold_full' => '4.5', 'threshold_half' => '4.0', 'target' => null, 'description' => null],
        ['group_name' => 'Chất lượng giảng dạy', 'code' => '2.4', 'name' => 'Feedback Big Test', 'weight' => 10, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Vận hành lớp', 'code' => '3.1', 'name' => 'Lên lịch học đúng hạn', 'weight' => 5, 'threshold_full' => '100%', 'threshold_half' => '90%', 'target' => null, 'description' => null],
        ['group_name' => 'Vận hành lớp', 'code' => '3.2', 'name' => 'Xử lý sự cố kỹ thuật', 'weight' => 5, 'threshold_full' => '< 2h', 'threshold_half' => '< 4h', 'target' => null, 'description' => null],
        ['group_name' => 'Quản lý Giáo viên', 'code' => '4.1', 'name' => 'Họp chuyên môn', 'weight' => 5, 'threshold_full' => '4 lần', 'threshold_half' => '2 lần', 'target' => null, 'description' => null],
        ['group_name' => 'Quản lý Giáo viên', 'code' => '4.2', 'name' => 'Tỷ lệ giữ chân GV', 'weight' => 7.5, 'threshold_full' => '100%', 'threshold_half' => '80%', 'target' => null, 'description' => null],
        ['group_name' => 'Phát triển Trung tâm', 'code' => '5.1', 'name' => 'Giới thiệu học viên mới', 'weight' => 7.5, 'threshold_full' => '5 HV', 'threshold_half' => '2 HV', 'target' => null, 'description' => null],
        ['group_name' => 'Phát triển Trung tâm', 'code' => '5.2', 'name' => 'Tham gia sự kiện', 'weight' => 2.5, 'threshold_full' => '2 sự kiện', 'threshold_half' => '1 sự kiện', 'target' => null, 'description' => null],
        ['group_name' => 'Chuyên môn khác', 'code' => '6.1', 'name' => 'Viết bài chuyên môn', 'weight' => 3.75, 'threshold_full' => '2 bài', 'threshold_half' => '1 bài', 'target' => null, 'description' => null],
        ['group_name' => 'Chuyên môn khác', 'code' => '6.2', 'name' => 'Đào tạo nội bộ', 'weight' => 3.75, 'threshold_full' => '1 buổi', 'threshold_half' => null, 'target' => null, 'description' => null],
    ];

    /**
     * KPI Học vụ theo mockup "Cấu hình KPI Học vụ" / "KPI tháng": 6 nhóm, 15 mục, quỹ 2.000.000đ/tháng.
     * Mỗi mục có nhóm, mã (1.1 … 6.2), ngưỡng đạt 100% / 50% và trọng số (% của quỹ).
     * Chưa có mục nào có mã → tạo 15 mục mặc định (LEGACY_ITEMS).
     */
    public function up(): void
    {
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->string('group_name')->nullable()->after('id');
            $table->string('code', 10)->nullable()->after('group_name');
            $table->string('threshold_full')->nullable()->after('target');
            $table->string('threshold_half')->nullable()->after('threshold_full');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        if (! DB::table('kpi_criteria')->whereNotNull('code')->exists()) {
            foreach (self::LEGACY_ITEMS as $i => $item) {
                DB::table('kpi_criteria')->insert($item + [
                    'unit' => '%',
                    'is_active' => true,
                    'sort_order' => $i + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('kpi_criteria')->whereIn('code', array_column(self::LEGACY_ITEMS, 'code'))->delete();
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropColumn(['group_name', 'code', 'threshold_full', 'threshold_half', 'sort_order']);
        });
    }
};

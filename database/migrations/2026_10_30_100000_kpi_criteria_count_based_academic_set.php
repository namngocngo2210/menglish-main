<?php

use App\Models\KpiCriterion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Bộ mặc định cũ (mockup) — chỉ xoá mềm những dòng còn nguyên mã + tên gốc, không đụng dòng Admin đã tự đặt. */
    private const LEGACY = [
        '1.1' => 'Nhắc học phí', '1.2' => 'Thu học phí', '1.3' => 'Hỗ trợ học viên yếu',
        '2.1' => 'Tỉ lệ hoàn thành bài tập', '2.2' => 'Điểm danh đầy đủ', '2.3' => 'Đánh giá từ học viên', '2.4' => 'Feedback Big Test',
        '3.1' => 'Lên lịch học đúng hạn', '3.2' => 'Xử lý sự cố kỹ thuật',
        '4.1' => 'Họp chuyên môn', '4.2' => 'Tỷ lệ giữ chân GV',
        '5.1' => 'Giới thiệu học viên mới', '5.2' => 'Tham gia sự kiện',
        '6.1' => 'Viết bài chuyên môn', '6.2' => 'Đào tạo nội bộ',
    ];

    /**
     * Tiêu chí KPI Học vụ theo file Excel KPI: đếm số lần lỗi, 3 mức 100 / 50 / 0%.
     * Thêm ngưỡng số (max_full / max_half) và thay bộ 15 mục mockup bằng bộ 15 tiêu chí của Excel.
     */
    public function up(): void
    {
        // MySQL không rollback DDL: lần chạy trước lỗi sau khi đã thêm cột thì chạy lại không vấp "duplicate column".
        if (! Schema::hasColumn('kpi_criteria', 'max_full')) {
            Schema::table('kpi_criteria', function (Blueprint $table) {
                $table->unsignedInteger('max_full')->nullable()->after('threshold_half');
                $table->unsignedInteger('max_half')->nullable()->after('max_full');
            });
        }

        foreach (self::LEGACY as $code => $name) {
            DB::table('kpi_criteria')->whereNull('deleted_at')->where('code', $code)->where('name', $name)
                ->update(['deleted_at' => now()]);
        }

        $order = (int) DB::table('kpi_criteria')->max('sort_order');
        foreach (KpiCriterion::DEFAULT_ACADEMIC_ITEMS as $i => $item) {
            if (DB::table('kpi_criteria')->whereNull('deleted_at')->where('code', $item['code'])->exists()) {
                continue;   // Admin đã có tiêu chí cùng mã: giữ nguyên
            }
            DB::table('kpi_criteria')->insert($item + [
                'threshold_full' => KpiCriterion::thresholdLabel($item['max_full'], $item['unit']),
                'threshold_half' => KpiCriterion::thresholdLabel($item['max_half'], $item['unit']),
                'is_active' => true,
                'sort_order' => $order + $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('kpi_criteria')->whereNotNull('max_full')->whereNull('deleted_at')->update(['deleted_at' => now()]);
        foreach (self::LEGACY as $code => $name) {
            DB::table('kpi_criteria')->where('code', $code)->where('name', $name)->update(['deleted_at' => null]);
        }
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropColumn(['max_full', 'max_half']);
        });
    }
};

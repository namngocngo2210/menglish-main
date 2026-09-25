<?php

use App\Models\KpiCriterion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KPI Học vụ theo mockup "Cấu hình KPI Học vụ" / "KPI tháng": 6 nhóm, 15 mục, quỹ 2.000.000đ/tháng.
     * Mỗi mục có nhóm, mã (1.1 … 6.2), ngưỡng đạt 100% / 50% và trọng số (% của quỹ).
     * Chưa có mục nào có mã → tạo 15 mục mặc định (KpiCriterion::DEFAULT_ACADEMIC_ITEMS).
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
            foreach (KpiCriterion::DEFAULT_ACADEMIC_ITEMS as $i => $item) {
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
        DB::table('kpi_criteria')->whereIn('code', array_column(KpiCriterion::DEFAULT_ACADEMIC_ITEMS, 'code'))->delete();
        Schema::table('kpi_criteria', function (Blueprint $table) {
            $table->dropColumn(['group_name', 'code', 'threshold_full', 'threshold_half', 'sort_order']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phiếu KPI theo mảng việc (kpi_evaluations.track): main = phiếu của vai trò chính, teaching = phiếu KPI giảng dạy (bộ
     * tiêu chí GV part-time) của Học thuật kiêm nhiệm giảng dạy. Mỗi nhân sự một phiếu mỗi kỳ cho từng mảng.
     */
    public function up(): void
    {
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->string('track', 20)->default('main')->after('year');
        });

        // Thêm khóa mới trước khi bỏ khóa cũ: MySQL cần một chỉ mục bắt đầu bằng user_id cho khóa ngoại.
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->unique(['user_id', 'month', 'year', 'track'], 'kpi_evaluations_user_period_track_unique');
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        DB::table('kpi_evaluations')->where('track', '!=', 'main')->get(['id'])
            ->each(fn ($row) => DB::table('kpi_evaluation_items')->where('kpi_evaluation_id', $row->id)->delete());
        DB::table('kpi_evaluations')->where('track', '!=', 'main')->delete();

        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->unique(['user_id', 'month', 'year']);
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->dropUnique('kpi_evaluations_user_period_track_unique');
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->dropColumn('track');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mockup KPI Học vụ (02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/03_tong_hop_kpi_danh_gia_thang, 04_kpi_thang):
     * - mục đánh giá: "Thực tế" (số liệu thực tế ghi nhận) + công tắc "Lỗi nghiêm trọng" (đưa % đạt về 0);
     * - phiếu đánh giá: nhận xét của quản lý theo 3 ô (Điểm tốt / Điểm cần cải thiện / Hành động tháng sau).
     */
    public function up(): void
    {
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->string('actual', 255)->nullable()->after('score');
            $table->boolean('critical_error')->default(false)->after('actual');
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->text('strengths')->nullable()->after('comment');
            $table->text('improvements')->nullable()->after('strengths');
            $table->text('next_actions')->nullable()->after('improvements');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_evaluation_items', function (Blueprint $table) {
            $table->dropColumn(['actual', 'critical_error']);
        });
        Schema::table('kpi_evaluations', function (Blueprint $table) {
            $table->dropColumn(['strengths', 'improvements', 'next_actions']);
        });
    }
};

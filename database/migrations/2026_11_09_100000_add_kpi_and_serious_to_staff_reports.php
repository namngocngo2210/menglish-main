<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký Học vụ (mockup 02/05): mỗi sự việc gắn được mã mục KPI Học vụ (vd. "1.2", lưu mã tại thời điểm ghi) và được
 * Admin xác nhận là "Lỗi nghiêm trọng" (người xác nhận + thời điểm; null = chưa xác nhận).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('kpi_code', 20)->nullable()->after('severity');
            $table->foreignId('serious_confirmed_by')->nullable()->after('kpi_code')->constrained('users')->nullOnDelete();
            $table->timestamp('serious_confirmed_at')->nullable()->after('serious_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serious_confirmed_by');
            $table->dropColumn(['kpi_code', 'serious_confirmed_at']);
        });
    }
};

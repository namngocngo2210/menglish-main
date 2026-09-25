<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Chỉnh tay bổ sung" trên màn Chi tiết chấm công GV (mockup epic-7/chi-tiet-cham-cong-theo-gv):
     * Học vụ / Admin sửa giờ vào / ra của một ca đã ghi nhận, bắt buộc lý do; ca được đánh dấu "Chỉnh tay".
     */
    public function up(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->timestamp('adjusted_at')->nullable()->after('rejection_reason');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted_at')->constrained('users')->nullOnDelete();
            $table->string('adjustment_reason', 500)->nullable()->after('adjusted_by');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropColumn(['adjusted_at', 'adjustment_reason']);
        });
    }
};

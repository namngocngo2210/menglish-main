<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo định kỳ mọi vai trò nộp xong được đẩy về Admin kiểm tra (14/10/2026): người / thời điểm Admin đánh dấu
 * "Đã kiểm tra". Người nộp sửa lại báo cáo → về lại "Chờ kiểm tra".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};

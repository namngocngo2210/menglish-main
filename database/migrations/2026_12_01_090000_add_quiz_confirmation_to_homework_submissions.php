<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TK-2026-0038: GV xác nhận bài Quiz (tùy chọn) trên CHÍNH bài nộp của HS. Thời điểm xác nhận (confirmed_at) tách khỏi
 * submitted_at của HS; created_by = GV tạo bài nộp thay HS khi HS chưa nộp (hủy xác nhận thì xóa bài đó).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->foreignId('confirmed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->foreignId('created_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('confirmed_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thẻ ca dạy 5 bước (chủ dự án 28/10/2026): Check-in → Điểm danh + Bài giảng → Giao bài tập → Nộp báo cáo. GV bấm "Dạy xong"
 * ở bước Bài giảng thì mới giao bài tập được — lưu thời điểm và người bấm trên buổi học.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->timestamp('lesson_done_at')->nullable();
            $table->foreignId('lesson_done_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_done_by');
            $table->dropColumn('lesson_done_at');
        });
    }
};

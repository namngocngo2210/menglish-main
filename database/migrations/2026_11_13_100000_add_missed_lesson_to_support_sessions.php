<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Học bù (chủ dự án 18/10/2026): học sinh nghỉ → Học vụ xếp buổi bổ trợ kèm thông tin buổi đã dạy, chuyển trợ giảng;
 * trợ giảng bổ trợ xong thì tick hoàn thành và nhận xét buổi (completion_note, bắt buộc từ nay).
 * - `missed_class_session_id`: buổi học sinh vắng mà buổi bổ trợ dạy lại (nguồn "Vắng học").
 * - `lesson_content`: nội dung buổi đã dạy (bài / từ vựng / ngữ pháp, lớp đã học gì, BTVN) Học vụ chuyển cho người bổ trợ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->foreignId('missed_class_session_id')->nullable()->after('class_session_id')->constrained('class_sessions')->nullOnDelete();
            $table->text('lesson_content')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('missed_class_session_id');
            $table->dropColumn('lesson_content');
        });
    }
};

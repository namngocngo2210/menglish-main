<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chấm test đầu vào theo khối lớp (BA chốt Q2): Nghe + Đọc&Viết + Nói (điểm thô theo thang khối),
 * tổng điểm → lớp đề xuất; lưu cả lớp đề xuất và lớp Học vụ chọn lại, nhận xét từng kỹ năng.
 * Giữ nguyên các cột cũ (reading_score / writing_score / overall_score / cefr_level) để tương thích dữ liệu cũ;
 * cefr_level không còn được ghi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->string('grade_group', 30)->nullable()->after('candidate_email');
            $table->decimal('reading_writing_score', 4, 1)->nullable()->after('writing_score');
            $table->decimal('total_score', 5, 1)->nullable()->after('overall_score');
            $table->string('suggested_class')->nullable()->after('recommended_course');
            $table->string('chosen_class')->nullable()->after('suggested_class');
            $table->text('listening_comment')->nullable()->after('teacher_comments');
            $table->text('reading_writing_comment')->nullable()->after('listening_comment');
            $table->text('speaking_comment')->nullable()->after('reading_writing_comment');
        });
    }

    public function down(): void
    {
        Schema::table('placement_test_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'grade_group', 'reading_writing_score', 'total_score', 'suggested_class', 'chosen_class',
                'listening_comment', 'reading_writing_comment', 'speaking_comment',
            ]);
        });
    }
};

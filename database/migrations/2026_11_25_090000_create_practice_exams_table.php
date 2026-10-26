<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đề cố định (docs/luyen-tap-ngan-hang-cau-hoi.md §11): nguyên bộ đề theo mã (vd. đề GK1 khối 6 mã 601) để học viên
 * chọn làm, bên cạnh đề trộn. Câu / ngữ liệu của đề nằm trong bảng ngân hàng câu hỏi, gắn `exam_id` (không vào kho trộn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('grade');
            $table->string('term', 3);
            $table->string('code', 20); // mã đề, vd. 601
            $table->string('title');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 10)->default('draft'); // draft | published | hidden
            $table->string('source_file')->nullable();
            $table->json('import_warnings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['grade', 'term', 'code']);
            $table->index(['grade', 'status']);
        });

        Schema::table('question_bank_passages', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->after('id')->constrained('practice_exams')->cascadeOnDelete();
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->after('id')->constrained('practice_exams')->cascadeOnDelete();
        });

        Schema::table('practice_attempts', function (Blueprint $table) {
            // null = đề trộn; xoá đề giữ lại lượt đã làm (bản chụp câu hỏi vẫn còn)
            $table->foreignId('exam_id')->nullable()->after('user_id')->constrained('practice_exams')->nullOnDelete();
            $table->index(['student_id', 'exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('practice_attempts', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'exam_id', 'status']);
            $table->dropConstrainedForeignId('exam_id');
        });
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_id');
        });
        Schema::table('question_bank_passages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_id');
        });
        Schema::dropIfExists('practice_exams');
    }
};

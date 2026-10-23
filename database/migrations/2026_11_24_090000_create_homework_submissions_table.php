<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homework 3 giỏ (docs/kho-syllabus.md §10, câu 7): bài nộp theo bài tập GV giao × học viên × giỏ (giấy = ảnh, video = video,
 * Quizizz = HS tự khai "Đã làm"). Nộp lại cùng giỏ thì cập nhật dòng cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homework_id')->constrained('homeworks')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('basket', 20);
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['homework_id', 'student_id', 'basket'], 'hw_sub_unique');
            $table->index('student_id', 'hw_sub_student_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
    }
};

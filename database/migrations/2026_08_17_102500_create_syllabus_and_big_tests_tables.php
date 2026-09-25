<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Giáo trình & Tài liệu
        Schema::create('syllabus_curriculums', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // CUR-IE65
            $table->string('title'); // MEnglish IELTS Intensive 6.5
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('version', 20)->default('v3.2');
            $table->string('file_type', 20)->default('PDF');
            $table->string('file_size', 50)->default('42 MB');
            $table->string('file_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 2. Bài học (Units)
        Schema::create('syllabus_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->integer('unit_number')->default(1);
            $table->string('title'); // Unit 04: Environment & Climate Change
            $table->text('objectives')->nullable(); // Mục tiêu bài học
            $table->text('vocabulary_focus')->nullable();
            $table->text('grammar_focus')->nullable();
            $table->text('homework_guide')->nullable();
            $table->timestamps();
        });

        // 3. Phân công biên soạn cho Giáo viên
        Schema::create('syllabus_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Giáo viên được giao
            $table->foreignId('curriculum_id')->constrained('syllabus_curriculums')->cascadeOnDelete();
            $table->string('assigned_chapters'); // Unit 5 - 8
            $table->date('deadline')->nullable();
            $table->integer('progress_percent')->default(0);
            $table->string('status', 50)->default('in_progress'); // in_progress, completed, overdue
            $table->timestamps();
        });

        // 4. Yêu cầu xin điều chỉnh tiến độ
        Schema::create('syllabus_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Giáo viên yêu cầu
            $table->string('request_type'); // Tăng 01 buổi phụ đạo, Lùi lịch thi
            $table->text('reason');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });

        // 5. Kỳ thi Big Test
        Schema::create('big_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // BT-2026-08
            $table->string('title'); // Mid-Term Big Test #08
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('test_type', 50)->default('midterm'); // midterm, final
            $table->dateTime('scheduled_at')->nullable();
            $table->string('room')->nullable(); // Phòng Lab 201
            $table->foreignId('proctor_id')->nullable()->constrained('users')->nullOnDelete(); // Giám thị
            $table->string('passcode')->nullable();
            $table->boolean('is_distributed')->default(false);
            $table->timestamps();
        });

        // 6. Bảng điểm Big Test & Gửi Zalo Phụ huynh
        Schema::create('big_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('big_test_id')->constrained('big_tests')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('listening_score', 4, 1)->default(0);
            $table->decimal('reading_score', 4, 1)->default(0);
            $table->decimal('writing_score', 4, 1)->default(0);
            $table->decimal('speaking_score', 4, 1)->default(0);
            $table->decimal('overall_score', 4, 1)->default(0);
            $table->text('progress_note')->nullable();
            $table->boolean('parent_notified')->default(false);
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('big_test_results');
        Schema::dropIfExists('big_tests');
        Schema::dropIfExists('syllabus_adjustment_requests');
        Schema::dropIfExists('syllabus_assignments');
        Schema::dropIfExists('syllabus_units');
        Schema::dropIfExists('syllabus_curriculums');
    }
};

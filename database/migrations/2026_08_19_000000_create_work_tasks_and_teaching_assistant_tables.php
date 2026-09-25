<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Bảng công việc / nhiệm vụ phân công
        Schema::create('work_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('lesson_session')->nullable(); // Ví dụ: Buổi 5 - Listening Practice
            $table->string('time_slot_category', 50)->default('during'); // before, during, after (Trước giờ học, Trong giờ học, Sau giờ học)
            $table->string('task_type', 50)->default('one_time'); // one_time (Phát sinh), recurring (Lặp đi lặp lại)
            $table->string('frequency', 50)->nullable(); // daily, weekly, monthly
            $table->date('due_date')->nullable();
            $table->string('due_time', 20)->nullable(); // e.g. 14:00
            $table->string('status', 50)->default('new'); // new, in_progress, pending_confirmation, blocked, completed, overdue, canceled
            $table->text('completion_note')->nullable();
            $table->text('completion_proof_image')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Bảng báo cáo trực lớp của Trợ giảng
        Schema::create('class_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained('work_tasks')->nullOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_name'); // Buổi 5 - Listening Practice
            $table->date('session_date')->default(now()->toDateString());
            $table->text('topics_learned'); // Hôm nay học gì (Bắt buộc)
            $table->text('teaching_log')->nullable(); // Nhật ký dạy (Tùy chọn)
            $table->text('board_image')->nullable(); // Ảnh bảng / lớp học
            $table->boolean('has_image')->default(false);
            $table->string('status', 50)->default('approved'); // approved (nếu có ảnh), pending_approval (nếu không có ảnh)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // 3. Học sinh cần bổ trợ ghi nhận trong báo cáo trực lớp
        Schema::create('class_report_student_supports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_report_id')->constrained('class_reports')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('absence_session')->nullable(); // Buổi vắng
            $table->text('reason'); // Lý do cần bổ trợ
            $table->text('action_plan')->nullable(); // Kế hoạch xử lý
            $table->timestamps();
        });

        // 4. Cấu hình lịch lớp & Năm học
        Schema::create('class_schedule_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('academic_year')->default('2024 - 2025');
            $table->string('slot1_day')->default('Thứ 5');
            $table->string('slot1_start')->default('18:00');
            $table->string('slot1_end')->default('19:30');
            $table->string('slot2_day')->default('Thứ 7');
            $table->string('slot2_start')->default('18:00');
            $table->string('slot2_end')->default('19:30');
            $table->timestamps();
        });

        // 5. Báo cáo nhu cầu nhân sự phòng/nhân sự
        Schema::create('hr_daily_demands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('report_date');
            $table->string('day_of_week');
            $table->integer('shift_count')->default(0);
            $table->integer('staff_needed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_daily_demands');
        Schema::dropIfExists('class_schedule_configs');
        Schema::dropIfExists('class_report_student_supports');
        Schema::dropIfExists('class_reports');
        Schema::dropIfExists('work_tasks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Khung trình độ
        Schema::create('course_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // A1, A2, B1, B2, C1, IE-50, IE-65, IE-70
            $table->string('name');
            $table->string('target'); // CEFR B2 / IELTS 6.5
            $table->string('duration')->nullable(); // 12 tuần / 24 buổi
            $table->integer('lessons_count')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Khóa học
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('course_level_id')->nullable()->constrained('course_levels')->nullOnDelete();
            $table->decimal('tuition_fee', 15, 2)->default(0);
            $table->integer('total_lessons')->default(24);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Lớp học
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // IE-2408
            $table->string('name');
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete(); // GV chính
            $table->foreignId('assistant_id')->nullable()->constrained('users')->nullOnDelete(); // Trợ giảng
            $table->string('schedule_text')->nullable(); // Tối T2-4-6 (19h30 - 21h30)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('max_capacity')->default(15);
            $table->string('status', 50)->default('active'); // active, upcoming, completed, cancelled
            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Học viên
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // HV-00109
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('address')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('current_class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('target')->nullable(); // IELTS 6.5
            $table->string('entrance_score')->nullable(); // Overall 5.0
            $table->string('midterm_score')->nullable();
            $table->string('final_score')->nullable();
            $table->integer('attended_lessons')->default(0);
            $table->integer('total_lessons')->default(24);
            $table->decimal('homework_rate', 5, 2)->default(95.0);
            $table->string('status', 50)->default('studying'); // studying, graduated, deferred, dropped
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 5. Xác nhận nhập học & Bàn giao
        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->date('enrolled_at')->nullable();
            $table->boolean('curriculum_delivered')->default(false);
            $table->boolean('zalo_group_added')->default(false);
            $table->string('status', 50)->default('completed'); // completed, pending
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_levels');
    }
};

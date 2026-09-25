<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cấu hình đơn giá giờ dạy theo rank
        Schema::create('teacher_rates', function (Blueprint $table) {
            $table->id();
            $table->string('rank_title'); // Junior Teacher, Senior Teacher, Native Speaker
            $table->string('criteria')->nullable(); // IELTS 7.0+, TESOL...
            $table->decimal('communication_rate', 15, 2)->default(180000);
            $table->decimal('ielts_rate', 15, 2)->default(220000);
            $table->timestamps();
        });

        // 2. Cấu hình bậc hoa hồng & thưởng tái tục
        Schema::create('commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_name'); // Mức 1, Mức 2, Mức 3
            $table->decimal('min_revenue', 15, 2)->default(0);
            $table->decimal('max_revenue', 15, 2)->nullable();
            $table->decimal('new_sale_percent', 5, 2)->default(3.0);
            $table->decimal('renew_percent', 5, 2)->default(5.0);
            $table->decimal('bonus_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        // 3. Kỳ tính lương
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // PR-2026-08
            $table->string('title'); // Bảng lương Tháng 08/2026
            $table->integer('month')->default(8);
            $table->integer('year')->default(2026);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 50)->default('draft'); // draft, reviewing, approved, paid
            $table->integer('total_staff')->default(0);
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        // 4. Chi tiết bảng lương nhân sự
        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('department', 50)->default('fulltime'); // fulltime, academic, operations, parttime
            $table->decimal('base_salary', 15, 2)->default(0); // Lương cứng định mức
            $table->decimal('standard_hours', 8, 2)->default(40); // Giờ chuẩn
            $table->decimal('actual_hours', 8, 2)->default(0); // Giờ thực dạy
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('teaching_salary', 15, 2)->default(0); // Thù lao dạy
            $table->decimal('kpi_bonus', 15, 2)->default(0); // Thưởng KPI
            $table->decimal('renew_bonus', 15, 2)->default(0); // Thưởng tái tục
            $table->decimal('allowance', 15, 2)->default(0); // Phụ cấp
            $table->decimal('insurance_deduction', 15, 2)->default(0); // BHXH
            $table->decimal('tax_deduction', 15, 2)->default(0); // Thuế TNCN
            $table->decimal('penalty_deduction', 15, 2)->default(0); // Phạt vi phạm
            $table->decimal('net_salary', 15, 2)->default(0); // Thực lĩnh
            $table->string('status', 50)->default('pending'); // pending, confirmed, paid
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 5. Chấm công ca dạy (Timesheets)
        Schema::create('teacher_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Giáo viên
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->date('teaching_date');
            $table->string('scheduled_time')->nullable(); // 19:30 - 21:30
            $table->string('checkin_time')->nullable(); // 19:20
            $table->decimal('hours', 5, 2)->default(2.0);
            $table->decimal('hourly_rate', 15, 2)->default(300000);
            $table->string('type', 50)->default('regular'); // regular, sub, 1on1, grading, workshop
            $table->string('status', 50)->default('valid'); // valid, invalid, pending_review
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 6. Lịch sử đồng bộ FaceID / Máy chấm công
        Schema::create('timesheet_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('device_name');
            $table->string('device_ip')->nullable();
            $table->integer('records_count')->default(0);
            $table->integer('matched_count')->default(0);
            $table->string('status', 50)->default('success');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_sync_logs');
        Schema::dropIfExists('teacher_timesheets');
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('commission_tiers');
        Schema::dropIfExists('teacher_rates');
    }
};

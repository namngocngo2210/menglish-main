<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chấm công hằng ngày của nhân sự trên điện thoại (ảnh khuôn mặt + GPS, giờ lấy theo máy chủ) và đơn xin duyệt
 * về chấm công (bổ sung công, xin đi muộn / về sớm, xin nghỉ). Mỗi người 1 dòng mỗi ngày.
 * Tên index / khoá ngoại đặt ngắn tay (MySQL giới hạn 64 ký tự); bảng đã có thì bỏ qua (chạy lại an toàn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_attendances')) {
            Schema::create('staff_attendances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->date('work_date');
                $table->string('source', 20)->default('mobile');
                $table->time('expected_start')->nullable();
                $table->time('expected_end')->nullable();

                $table->dateTime('check_in_at')->nullable();
                $table->string('check_in_photo')->nullable();
                $table->decimal('check_in_lat', 10, 7)->nullable();
                $table->decimal('check_in_lng', 10, 7)->nullable();
                $table->unsignedInteger('check_in_distance')->nullable();
                $table->unsignedInteger('check_in_accuracy')->nullable();

                $table->dateTime('check_out_at')->nullable();
                $table->string('check_out_photo')->nullable();
                $table->decimal('check_out_lat', 10, 7)->nullable();
                $table->decimal('check_out_lng', 10, 7)->nullable();
                $table->unsignedInteger('check_out_distance')->nullable();
                $table->unsignedInteger('check_out_accuracy')->nullable();

                $table->unsignedSmallInteger('late_minutes')->default(0);
                $table->unsignedSmallInteger('early_minutes')->default(0);
                $table->boolean('late_excused')->default(false);
                $table->foreignId('penalty_id')->nullable()->constrained('penalties')->nullOnDelete();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'work_date'], 'staff_att_user_date_unique');
                $table->index(['branch_id', 'work_date'], 'staff_att_branch_date_idx');
            });
        }

        if (! Schema::hasTable('staff_attendance_requests')) {
            Schema::create('staff_attendance_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('type', 20);
                $table->date('date_from');
                $table->date('date_to');
                $table->time('check_in_time')->nullable();
                $table->time('check_out_time')->nullable();
                $table->text('reason');
                $table->string('status', 20)->default('pending');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('reviewed_at')->nullable();
                $table->string('rejection_reason', 1000)->nullable();
                $table->timestamps();

                $table->index(['status', 'branch_id'], 'staff_att_req_status_branch_idx');
                $table->index(['user_id', 'date_from'], 'staff_att_req_user_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_requests');
        Schema::dropIfExists('staff_attendances');
    }
};

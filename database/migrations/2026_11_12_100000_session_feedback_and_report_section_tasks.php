<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thiết kế lại báo cáo theo vai trò (chủ dự án 17/10/2026):
 * - `class_session_feedbacks`: góp ý cho từng buổi học. Phụ huynh chấm sao trên cổng học viên (`source = parent`, theo học sinh),
 *   Học vụ góp ý ngay trong báo cáo ngày, không cần dự giờ (`source = staff`, theo người góp ý). Báo Admin gấp tạo sự việc
 *   mức "Khẩn" trong Nhật ký (`escalation_id`).
 * - `work_tasks.staff_report_id` / `report_section`: task follow-up gắn với báo cáo và khối của báo cáo, để việc chưa xong
 *   hiện lại đúng khối đó ở báo cáo kỳ sau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_session_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('source', 20);
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('verdict', 20)->nullable();
            $table->json('categories')->nullable();
            $table->text('content')->nullable();
            $table->foreignId('staff_report_id')->nullable()->constrained('staff_reports')->nullOnDelete();
            $table->foreignId('escalation_id')->nullable()->constrained('staff_reports')->nullOnDelete();
            $table->timestamps();

            $table->index(['class_session_id', 'source']);
            $table->index(['class_id', 'created_at']);
        });

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('staff_report_id')->nullable()->after('class_id')->constrained('staff_reports')->nullOnDelete();
            $table->string('report_section', 40)->nullable()->after('staff_report_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_report_id');
            $table->dropColumn('report_section');
        });
        Schema::dropIfExists('class_session_feedbacks');
    }
};

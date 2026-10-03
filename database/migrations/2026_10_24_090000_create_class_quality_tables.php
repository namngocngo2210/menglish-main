<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Các màn Học vụ / Học thuật còn thiếu so với mockup (tài liệu Owen):
 *  - qa_observations: dự giờ đội vận hành (QA / Học vụ), mỗi lượt = 1 buổi quan sát lớp của một giáo viên + xếp loại.
 *  - academic_observations: đánh giá dự giờ học thuật theo lớp + tháng (6 tiêu chí, % chuyên cần, % đạt yêu cầu).
 *  - class_checklists: checklist học phí & feedback Big Test theo lớp + tháng (Có / Không / N-A) để tính KPI Học vụ.
 *  - teacher_meeting_reports: báo cáo họp giáo viên theo tuần do Học thuật ghi.
 *  - staff_reports.period_key / data: báo cáo có cấu trúc (tuần Học vụ, tháng / quý Học thuật) lưu số liệu theo kỳ.
 *
 * Tên index / khóa ngoại đặt ngắn vì MySQL giới hạn 64 ký tự.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes', indexName: 'qa_obs_class_fk');
            $table->foreignId('teacher_id')->constrained('users', indexName: 'qa_obs_teacher_fk');
            $table->foreignId('observer_id')->constrained('users', indexName: 'qa_obs_observer_fk');
            $table->date('observed_on');
            $table->text('lesson_content')->nullable();
            $table->text('attitude')->nullable();
            $table->text('preparation')->nullable();
            $table->text('technique')->nullable();
            $table->text('suggestions')->nullable();
            $table->string('rating', 20);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['observed_on'], 'qa_obs_date_idx');
        });

        Schema::create('academic_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes', indexName: 'acad_obs_class_fk');
            $table->char('month', 7);
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->decimal('pass_rate', 5, 2)->nullable();
            $table->boolean('observed')->default(false);
            $table->date('observed_on')->nullable();
            $table->foreignId('observer_id')->nullable()->constrained('users', indexName: 'acad_obs_observer_fk');
            $table->text('teaching_quality')->nullable();
            $table->text('lesson_content')->nullable();
            $table->text('interaction')->nullable();
            $table->text('attitude')->nullable();
            $table->text('effectiveness')->nullable();
            $table->text('overall')->nullable();
            $table->text('action_notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'acad_obs_updater_fk');
            $table->timestamps();
            $table->unique(['class_id', 'month'], 'acad_obs_class_month_uq');
        });

        Schema::create('class_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes', indexName: 'class_chk_class_fk');
            $table->char('month', 7);
            $table->string('tuition_due', 3)->nullable();
            $table->string('tuition_reminded', 3)->nullable();
            $table->string('tuition_collected', 3)->nullable();
            $table->text('tuition_note')->nullable();
            $table->string('big_test_due', 3)->nullable();
            $table->string('feedback_on_time', 3)->nullable();
            $table->string('negative_feedback_handled', 3)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'class_chk_updater_fk');
            $table->timestamps();
            $table->unique(['class_id', 'month'], 'class_chk_class_month_uq');
        });

        Schema::create('teacher_meeting_reports', function (Blueprint $table) {
            $table->id();
            $table->date('week_start');
            $table->foreignId('teacher_id')->constrained('users', indexName: 'tmr_teacher_fk');
            $table->foreignId('class_id')->nullable()->constrained('classes', indexName: 'tmr_class_fk');
            $table->foreignId('author_id')->constrained('users', indexName: 'tmr_author_fk');
            $table->text('syllabus_note')->nullable();
            $table->text('scores_note')->nullable();
            $table->text('class_note');
            $table->text('academic_order')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('status', 20);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['week_start'], 'tmr_week_idx');
        });

        Schema::table('staff_reports', function (Blueprint $table) {
            $table->string('period_key', 10)->nullable()->after('report_date');
            $table->json('data')->nullable()->after('content');
            $table->index(['user_id', 'type', 'period_key'], 'staff_reports_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('staff_reports', function (Blueprint $table) {
            $table->dropIndex('staff_reports_period_idx');
            $table->dropColumn(['period_key', 'data']);
        });
        Schema::dropIfExists('teacher_meeting_reports');
        Schema::dropIfExists('class_checklists');
        Schema::dropIfExists('academic_observations');
        Schema::dropIfExists('qa_observations');
    }
};

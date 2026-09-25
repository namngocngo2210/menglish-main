<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->foreignId('class_id')->nullable()->after('curriculum_id')->constrained('classes')->nullOnDelete();
            $table->string('stage_name')->nullable()->after('assigned_chapters');
        });

        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->foreignId('class_session_id')->nullable()->after('class_id')->constrained('class_sessions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->unique(['user_id', 'class_session_id'], 'teacher_timesheet_user_session_unique');
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('class_session_id')->nullable()->after('class_id')->constrained('class_sessions')->nullOnDelete();
            $table->string('review_status', 30)->default('pending_review')->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
        });

        Schema::table('big_tests', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->after('is_distributed');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('distributed_at')->nullable()->after('approved_at');
            $table->string('content_url')->nullable()->after('passcode');
        });

        Schema::table('big_test_results', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->after('progress_note');
            $table->foreignId('graded_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('graded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->unique(['big_test_id', 'student_id'], 'big_test_student_unique');
        });

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_report_student_support_id')->nullable()->constrained('class_report_student_supports')->nullOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->text('reason')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');

        Schema::table('big_test_results', function (Blueprint $table) {
            $table->dropUnique('big_test_student_unique');
            $table->dropConstrainedForeignId('graded_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at']);
        });

        Schema::table('big_tests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'approved_at', 'distributed_at', 'content_url']);
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_session_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'reviewed_at', 'review_note']);
        });

        Schema::table('teacher_timesheets', function (Blueprint $table) {
            $table->dropUnique('teacher_timesheet_user_session_unique');
            $table->dropConstrainedForeignId('class_session_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'rejection_reason']);
        });

        Schema::table('syllabus_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_id');
            $table->dropColumn('stage_name');
        });
    }
};

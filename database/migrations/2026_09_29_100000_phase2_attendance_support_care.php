<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (vòng 2): điểm danh theo từng buổi học, danh sách bổ trợ tự động, chăm sóc tháng đầu.
 *
 * - student_attendances: khóa theo (buổi học, học viên) thay vì (lớp, học viên, ngày) để một ngày
 *   có thể điểm danh cả buổi chính khóa lẫn buổi học bù/phụ đạo; thêm recorded_by (người thực sự
 *   bấm lưu — Học vụ/Quản lý điểm danh thay GV); gắn buổi học cho dữ liệu cũ chưa có class_session_id.
 * - class_report_student_supports: là "danh sách bổ trợ" chung, không chỉ từ báo cáo trực lớp:
 *   thêm lớp, nguồn (vắng / mini test / Big Test / báo cáo), bản ghi nguồn, điểm.
 * - work_tasks: gắn học viên + mốc chăm sóc (ngày 3/7/14/30) để lệnh tạo việc chạy lại không trùng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            // Giữ chỉ mục cho khóa ngoại class_id trước khi bỏ unique cũ (MySQL yêu cầu).
            $table->index('class_id', 'student_attendances_class_idx');
        });

        // Gắn buổi học cho điểm danh cũ (trước đây lưu theo ngày): buổi chính khóa/học bù đầu tiên
        // chưa hủy của lớp trong ngày đó.
        $orphans = DB::table('student_attendances')->whereNull('class_session_id')
            ->select('id', 'class_id', 'session_date')->orderBy('id')->get();
        foreach ($orphans as $row) {
            $sessionId = DB::table('class_sessions')
                ->where('class_id', $row->class_id)
                ->whereDate('date', substr((string) $row->session_date, 0, 10))
                ->where('type', '!=', 'support')
                ->where('status', '!=', 'cancelled')
                ->orderBy('start_time')
                ->value('id');
            if ($sessionId) {
                DB::table('student_attendances')->where('id', $row->id)->update(['class_session_id' => $sessionId]);
            }
        }

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'student_id', 'session_date']);
            $table->unique(['class_session_id', 'student_id'], 'student_attendance_session_student_unique');
            $table->index(['class_id', 'student_id', 'session_date'], 'student_attendances_class_student_date_idx');
        });

        Schema::table('class_report_student_supports', function (Blueprint $table) {
            $table->unsignedBigInteger('class_report_id')->nullable()->change();
            $table->foreignId('class_id')->nullable()->after('class_report_id')->constrained('classes')->cascadeOnDelete();
            $table->string('source', 30)->default('class_report')->after('student_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source');
            $table->decimal('score', 5, 2)->nullable()->after('source_id');
            $table->unique(['student_id', 'class_id', 'source', 'source_id'], 'support_list_source_unique');
        });

        // Dữ liệu cũ từ báo cáo trực lớp: lấy lớp theo báo cáo.
        DB::table('class_report_student_supports')->whereNull('class_id')->orderBy('id')->get(['id', 'class_report_id'])
            ->each(function ($row) {
                $classId = DB::table('class_reports')->where('id', $row->class_report_id)->value('class_id');
                if ($classId) {
                    DB::table('class_report_student_supports')->where('id', $row->id)->update(['class_id' => $classId]);
                }
            });

        Schema::table('work_tasks', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('class_id')->constrained('students')->nullOnDelete();
            $table->unsignedSmallInteger('care_milestone')->nullable()->after('student_id');
            $table->unique(['student_id', 'care_milestone'], 'work_tasks_student_care_unique');
        });
    }

    public function down(): void
    {
        Schema::table('work_tasks', function (Blueprint $table) {
            $table->dropUnique('work_tasks_student_care_unique');
            $table->dropConstrainedForeignId('student_id');
            $table->dropColumn('care_milestone');
        });

        Schema::table('class_report_student_supports', function (Blueprint $table) {
            $table->dropUnique('support_list_source_unique');
            $table->dropConstrainedForeignId('class_id');
            $table->dropColumn(['source', 'source_id', 'score']);
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropIndex('student_attendances_class_student_date_idx');
            $table->dropUnique('student_attendance_session_student_unique');
            $table->unique(['class_id', 'student_id', 'session_date']);
            $table->dropIndex('student_attendances_class_idx');
            $table->dropConstrainedForeignId('recorded_by');
        });
    }
};

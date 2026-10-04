<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ngày bắt đầu vào học (để biết học viên học lâu năm, tri ân 3–5 năm). Tự điền theo buổi có mặt đầu tiên,
        // Học vụ sửa tay được cho học viên học từ trước khi dùng hệ thống.
        Schema::table('students', function (Blueprint $table) {
            $table->date('study_started_on')->nullable()->after('status');
            $table->index('study_started_on', 'students_study_started_idx');
        });

        DB::table('student_attendances')
            ->whereIn('status', ['present', 'late'])
            ->whereNotNull('session_date')
            ->selectRaw('student_id, MIN(session_date) as first_date')
            ->groupBy('student_id')
            ->orderBy('student_id')
            ->get()
            ->each(fn ($row) => DB::table('students')->where('id', $row->student_id)->whereNull('study_started_on')
                ->update(['study_started_on' => substr((string) $row->first_date, 0, 10)]));
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_study_started_idx');
            $table->dropColumn('study_started_on');
        });
    }
};

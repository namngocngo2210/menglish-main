<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ buổi học viên (logic tính học phí mới, 04/10/2026):
 * - student_tuitions.session_count: số buổi khoản học phí bao gồm (đơn giá buổi = học phí sau ưu đãi khi chốt / số buổi).
 * - tuition_receipts: số buổi thu + đơn giá, học liệu / thi / khác của phiếu lập theo buổi.
 * - class_enrollments.left_at: ngày rời lớp (chuyển lớp, thôi học) — hết ngày này không trừ buổi của lớp nữa.
 */
return new class extends Migration
{
    private const DEFAULT_COURSE_SESSIONS = 24;

    public function up(): void
    {
        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_count')->nullable()->after('total_amount');
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->unsignedSmallInteger('session_count')->nullable()->after('tuition_amount');
            $table->decimal('session_unit_price', 15, 2)->nullable()->after('session_count');
            $table->decimal('material_fee', 15, 2)->default(0)->after('session_unit_price');
            $table->decimal('exam_fee', 15, 2)->default(0)->after('material_fee');
            $table->decimal('other_fee', 15, 2)->default(0)->after('exam_fee');
            $table->string('other_fee_reason', 255)->nullable()->after('other_fee');
        });

        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->date('left_at')->nullable()->after('enrolled_at');
        });

        $this->backfillTuitionSessions();
        $this->backfillEnrollmentLeftAt();
    }

    /** Khoản học phí cũ: số buổi = số buổi của khóa (lớp của khoản học phí, không có thì lớp đang học), mặc định 24. */
    private function backfillTuitionSessions(): void
    {
        DB::table('student_tuitions')
            ->leftJoin('classes as tc', 'tc.id', '=', 'student_tuitions.class_id')
            ->leftJoin('students', 'students.id', '=', 'student_tuitions.student_id')
            ->leftJoin('classes as sc', 'sc.id', '=', 'students.current_class_id')
            ->leftJoin('courses as c1', 'c1.id', '=', 'tc.course_id')
            ->leftJoin('courses as c2', 'c2.id', '=', 'sc.course_id')
            ->whereNull('student_tuitions.session_count')
            ->select('student_tuitions.id', 'c1.total_lessons as tuition_lessons', 'c2.total_lessons as current_lessons')
            ->orderBy('student_tuitions.id')
            ->get()
            ->each(function ($row) {
                $sessions = (int) ($row->tuition_lessons ?: $row->current_lessons ?: self::DEFAULT_COURSE_SESSIONS);
                DB::table('student_tuitions')->where('id', $row->id)->update(['session_count' => max(1, $sessions)]);
            });
    }

    /**
     * Lượt xếp lớp đã thôi học: rời lớp ngày chuyển "thôi học". Lớp chính cũ bị thay bằng lớp mới qua "Xếp lớp"
     * (lượt còn hiệu lực, khác lớp đang học, xếp trước lượt của lớp đang học): rời lớp ngày xếp lớp mới.
     * Lớp liên kết (học song song, xếp sau lớp chính) giữ nguyên.
     */
    private function backfillEnrollmentLeftAt(): void
    {
        DB::table('class_enrollments')
            ->where('status', 'dropped')
            ->whereNull('left_at')
            ->select('id', 'updated_at', 'enrolled_at')
            ->orderBy('id')
            ->get()
            ->each(fn ($row) => DB::table('class_enrollments')->where('id', $row->id)
                ->update(['left_at' => substr((string) ($row->updated_at ?? $row->enrolled_at ?? now()), 0, 10)]));

        $current = DB::table('class_enrollments')
            ->join('students', function ($join) {
                $join->on('students.id', '=', 'class_enrollments.student_id')
                    ->on('students.current_class_id', '=', 'class_enrollments.class_id');
            })
            ->whereIn('class_enrollments.status', ['pending', 'completed'])
            ->whereNotNull('class_enrollments.enrolled_at')
            ->select('class_enrollments.student_id', 'class_enrollments.class_id', 'class_enrollments.id', 'class_enrollments.enrolled_at')
            ->orderBy('class_enrollments.id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->sortByDesc('id')->first());

        foreach ($current as $studentId => $main) {
            $mainDate = substr((string) $main->enrolled_at, 0, 10);
            DB::table('class_enrollments')
                ->where('student_id', $studentId)
                ->where('class_id', '!=', $main->class_id)
                ->whereIn('status', ['pending', 'completed'])
                ->whereNull('left_at')
                ->where('id', '<', $main->id)
                ->whereDate('enrolled_at', '<=', $mainDate)
                ->update(['left_at' => $mainDate]);
        }
    }

    public function down(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });

        Schema::table('tuition_receipts', function (Blueprint $table) {
            $table->dropColumn(['session_count', 'session_unit_price', 'material_fee', 'exam_fee', 'other_fee', 'other_fee_reason']);
        });

        Schema::table('student_tuitions', function (Blueprint $table) {
            $table->dropColumn('session_count');
        });
    }
};

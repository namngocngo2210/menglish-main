<?php

namespace App\Services;

use App\Models\AcademicRecord;
use App\Models\ClassModel;
use App\Models\Homework;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Models\WorkTask;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bảng KPI tự động: các chỉ số tính từ dữ liệu thật trong kỳ (tháng) cho từng nhân sự giảng dạy.
 * Chỉ số không có dữ liệu trả về null để giao diện hiển thị "Chưa có dữ liệu" thay vì một con số giả.
 */
class KpiBoardService
{
    /** Vai trò được theo dõi trên bảng KPI. */
    public const ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant', 'academic_staff'];

    /** Trang "Học tập của tôi" nơi học viên nộp bài tập (AcademicRecord). */
    public const HOMEWORK_SUBMISSION_SCREEN = '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap';

    public function staffQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', self::ROLES))
            ->orderBy('name');
    }

    /**
     * @return array{classes: int, retention: ?float, retention_detail: string, attendance: ?float, attendance_detail: string,
     *               homework: ?float, homework_detail: string, tasks: ?float, tasks_detail: string}
     */
    public function metricsFor(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $classIds = ClassModel::query()
            ->where(fn (Builder $q) => $q->where('teacher_id', $user->id)
                ->orWhere('assistant_id', $user->id)
                ->orWhere('foreign_teacher_id', $user->id))
            ->pluck('id');

        // Giữ chân: học viên của các lớp phụ trách chưa "Thôi học" / tổng học viên đã vào lớp.
        $students = Student::whereIn('current_class_id', $classIds)->where('status', '!=', 'waiting_start');
        $totalStudents = (clone $students)->count();
        $retained = (clone $students)->where('status', '!=', 'dropped')->count();

        // Chuyên cần: lượt có mặt/đi muộn trên tổng lượt điểm danh trong kỳ (bỏ bản ghi bị từ chối khi rà soát).
        $attendance = StudentAttendance::whereIn('class_id', $classIds)
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'));
        $attendanceTotal = (clone $attendance)->count();
        $attendancePresent = (clone $attendance)->whereIn('status', ['present', 'late'])->count();

        // Bài tập: bài nộp của học viên trong kỳ / (số bài giao trong kỳ × sĩ số lớp).
        [$homeworkExpected, $homeworkSubmitted] = $this->homework($classIds->all(), $from, $to);

        // Công việc được giao có hạn trong kỳ đã hoàn thành.
        $tasks = WorkTask::where('assignee_id', $user->id)
            ->whereDate('due_date', '>=', $from->toDateString())
            ->whereDate('due_date', '<=', $to->toDateString());
        $tasksTotal = (clone $tasks)->count();
        $tasksDone = (clone $tasks)->where('status', 'completed')->count();

        return [
            'classes' => $classIds->count(),
            'retention' => self::rate($retained, $totalStudents),
            'retention_detail' => "{$retained}/{$totalStudents} học viên",
            'attendance' => self::rate($attendancePresent, $attendanceTotal),
            'attendance_detail' => "{$attendancePresent}/{$attendanceTotal} lượt",
            'homework' => $homeworkExpected ? min(100.0, self::rate($homeworkSubmitted, $homeworkExpected)) : null,
            'homework_detail' => "{$homeworkSubmitted}/{$homeworkExpected} bài",
            'tasks' => self::rate($tasksDone, $tasksTotal),
            'tasks_detail' => "{$tasksDone}/{$tasksTotal} việc",
        ];
    }

    /**
     * @param  list<int>  $classIds
     * @return array{0: int, 1: int} [số bài phải nộp, số bài đã nộp]
     */
    private function homework(array $classIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if (empty($classIds)) {
            return [0, 0];
        }

        $homeworkByClass = Homework::whereIn('class_id', $classIds)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $due) => $due->whereNotNull('due_date')
                    ->whereDate('due_date', '>=', $from->toDateString())
                    ->whereDate('due_date', '<=', $to->toDateString()))
                ->orWhere(fn (Builder $created) => $created->whereNull('due_date')
                    ->whereDate('created_at', '>=', $from->toDateString())
                    ->whereDate('created_at', '<=', $to->toDateString())))
            ->selectRaw('class_id, count(*) as total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');
        if ($homeworkByClass->isEmpty()) {
            return [0, 0];
        }

        $studentIdsByClass = Student::whereIn('current_class_id', $homeworkByClass->keys())
            ->whereIn('status', ClassModel::SEAT_HOLDING_STUDENT_STATUSES)
            ->get(['id', 'current_class_id'])
            ->groupBy('current_class_id');

        $expected = $homeworkByClass->reduce(fn (int $carry, $total, $classId) => $carry
            + (int) $total * ($studentIdsByClass->get($classId)?->count() ?? 0), 0);
        $studentIds = $studentIdsByClass->flatten()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $submitted = empty($studentIds) ? 0 : AcademicRecord::where('screen_key', self::HOMEWORK_SUBMISSION_SCREEN)
            ->whereIn('data->student_id', $studentIds)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->count();

        return [$expected, $submitted];
    }

    private static function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}

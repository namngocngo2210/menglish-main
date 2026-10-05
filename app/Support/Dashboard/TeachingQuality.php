<?php

namespace App\Support\Dashboard;

use App\Models\AcademicRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Homework;
use App\Models\MiniTestScore;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\StaffAttendanceRequest;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Số liệu chất lượng giảng dạy theo tháng cho Tổng quan (chủ dự án 04/10/2026), lọc từ dữ liệu giáo viên nhập hằng ngày:
 *  - Theo người: ngày công, buổi đi muộn, ngày nghỉ phép, lỗi vi phạm.
 *  - Theo lớp: sĩ số, học sinh mới / nghỉ trong tháng, tỷ lệ chuyên cần, tỷ lệ làm bài về nhà, điểm trung bình tháng.
 *
 * Cách tính (mặc định, chưa có quy định riêng):
 *  - Ngày công = số ngày có giờ dạy (teacher_timesheets) hoặc có chấm công vào (staff_attendances).
 *  - Buổi đi muộn = buổi dạy check-in muộn + ngày chấm công muộn không được duyệt (ngày đã có buổi dạy muộn không tính lại).
 *  - Phép = số ngày trong tháng của đơn xin nghỉ đã duyệt.
 *  - Vi phạm = biên bản lỗi trong tháng (trừ biên bản đã hủy).
 *  - Chuyên cần = (Có mặt + Đi muộn) / tổng lượt điểm danh trong tháng (vắng có phép tính là vắng).
 *  - Làm BTVN = lượt học sinh có bài nộp trong hạn của mỗi bài tập giao trong tháng / (số bài tập × sĩ số lớp).
 *    Hạn = hạn nộp của bài (không có thì 7 ngày sau khi giao).
 *  - Điểm trung bình = điểm mini test trong tháng quy về thang 10.
 */
final class TeachingQuality
{
    /** Hạn nộp mặc định khi bài tập không đặt hạn. */
    private const HOMEWORK_DEFAULT_DAYS = 7;

    public const SUBMISSION_SCREEN = '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap';

    public readonly Carbon $from;

    public readonly Carbon $to;

    public function __construct(?string $month = null)
    {
        $start = ReportPeriod::parseMonth($month);
        $this->from = $start->copy()->startOfMonth()->startOfDay();
        $this->to = $start->copy()->endOfMonth()->endOfDay();
    }

    public function monthKey(): string
    {
        return $this->from->format('Y-m');
    }

    public function monthLabel(): string
    {
        return 'Tháng '.$this->from->format('m/Y');
    }

    /**
     * Lớp người này đang giữ: lớp chưa kết thúc mà là GV chính / trợ giảng / GVNN, cộng lớp có buổi người này dạy trong tháng.
     *
     * @return Collection<int, ClassModel>
     */
    public function classesOf(User $user): Collection
    {
        $sessionClassIds = ClassSession::query()->forStaff($user->id)
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->distinct()->pluck('class_id');

        return ClassModel::query()
            ->where(fn ($q) => $q->where(fn ($own) => $own->where('status', 'active')
                ->where(fn ($who) => $who->where('teacher_id', $user->id)->orWhere('assistant_id', $user->id)->orWhere('foreign_teacher_id', $user->id)))
                ->orWhereIn('id', $sessionClassIds))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'branch_id', 'teacher_id', 'status']);
    }

    /**
     * Số liệu từng lớp trong tháng.
     *
     * @param  Collection<int, ClassModel>  $classes
     * @return list<array{id: int, code: ?string, name: string, teacher_id: ?int, students: int, new: int, dropped: int, attendance: ?float, homework: ?float, score: ?float}>
     */
    public function classMetrics(Collection $classes): array
    {
        $ids = $classes->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($ids === []) {
            return [];
        }
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();
        $rosters = ClassModel::rosterStudentsFor($classes);

        $attendance = StudentAttendance::query()->whereIn('class_id', $ids)->whereBetween('session_date', [$from, $to])
            ->selectRaw("class_id, count(*) as total, sum(case when status in ('present', 'late') then 1 else 0 end) as attended")
            ->groupBy('class_id')->get()->keyBy('class_id');

        $scores = MiniTestScore::query()->whereIn('class_id', $ids)->whereBetween('test_date', [$from, $to])
            ->where('max_score', '>', 0)->get(['class_id', 'score', 'max_score'])
            ->groupBy('class_id')
            ->map(fn (Collection $rows) => $rows->avg(fn (MiniTestScore $s) => min(10, (float) $s->score / (float) $s->max_score * 10)));

        $enrollments = ClassEnrollment::query()->whereIn('class_id', $ids)
            ->where(fn ($q) => $q->whereBetween('enrolled_at', [$from, $to])
                ->orWhere(fn ($n) => $n->whereNull('enrolled_at')->whereBetween('created_at', [$this->from, $this->to]))
                ->orWhere(fn ($d) => $d->where('status', Student::ENROLLMENT_DROPPED)->whereBetween('updated_at', [$this->from, $this->to])))
            ->get(['class_id', 'status', 'enrolled_at', 'created_at', 'updated_at']);

        $homework = $this->homeworkRates($ids, $rosters);

        return $classes->map(function (ClassModel $class) use ($rosters, $attendance, $scores, $enrollments, $homework) {
            $att = $attendance->get($class->id);
            $own = $enrollments->where('class_id', $class->id);
            $joined = fn (ClassEnrollment $e) => ($e->enrolled_at ?? $e->created_at)?->between($this->from, $this->to);

            return [
                'id' => (int) $class->id,
                'code' => $class->code,
                'name' => $class->name,
                'teacher_id' => $class->teacher_id ? (int) $class->teacher_id : null,
                'students' => $rosters->get($class->id, collect())->count(),
                'new' => $own->filter(fn (ClassEnrollment $e) => $e->status !== Student::ENROLLMENT_DROPPED && $joined($e))->count(),
                'dropped' => $own->filter(fn (ClassEnrollment $e) => $e->status === Student::ENROLLMENT_DROPPED && $e->updated_at?->between($this->from, $this->to))->count(),
                'attendance' => $att && $att->total > 0 ? round($att->attended / $att->total * 100, 1) : null,
                'homework' => $homework[$class->id] ?? null,
                'score' => $scores->has($class->id) ? round((float) $scores->get($class->id), 1) : null,
            ];
        })->values()->all();
    }

    /**
     * Tỷ lệ làm bài về nhà theo lớp (null = lớp không giao bài trong tháng).
     *
     * @param  list<int>  $classIds
     * @param  Collection<int, Collection<int, Student>>  $rosters
     * @return array<int, float>
     */
    private function homeworkRates(array $classIds, Collection $rosters): array
    {
        $homeworks = Homework::query()->whereIn('class_id', $classIds)->whereBetween('created_at', [$this->from, $this->to])
            ->get(['id', 'class_id', 'created_at', 'due_date', 'due_at']);
        if ($homeworks->isEmpty()) {
            return [];
        }

        $studentIds = $rosters->only($homeworks->pluck('class_id')->unique()->all())->flatten()->pluck('id')->unique()
            ->map(fn ($id) => (string) $id)->values();
        $submissions = $studentIds->isEmpty() ? collect() : AcademicRecord::query()
            ->where('screen_key', self::SUBMISSION_SCREEN)
            ->whereIn('data->student_id', $studentIds->all())
            ->whereBetween('created_at', [$this->from, $this->to->copy()->addDays(self::HOMEWORK_DEFAULT_DAYS + 31)])
            ->get(['data', 'created_at'])
            ->groupBy(fn (AcademicRecord $r) => (string) data_get($r->data, 'student_id'));

        $rates = [];
        foreach ($homeworks->groupBy('class_id') as $classId => $items) {
            $roster = $rosters->get((int) $classId, collect())->pluck('id')->map(fn ($id) => (string) $id);
            if ($roster->isEmpty()) {
                continue;
            }
            $done = 0;
            foreach ($items as $hw) {
                $deadline = $hw->due_at ?? ($hw->due_date ? Carbon::parse($hw->due_date)->endOfDay() : $hw->created_at->copy()->addDays(self::HOMEWORK_DEFAULT_DAYS));
                $done += $roster->filter(fn (string $sid) => $submissions->get($sid, collect())
                    ->contains(fn (AcademicRecord $r) => $r->created_at->between($hw->created_at, $deadline)))->count();
            }
            $rates[(int) $classId] = round($done / ($items->count() * $roster->count()) * 100, 1);
        }

        return $rates;
    }

    /**
     * Ngày công, buổi đi muộn, ngày phép, lỗi vi phạm trong tháng của từng người.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{work_days: int, late: int, leave_days: int, violations: int}>
     */
    public function workStats(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $from = $this->from->toDateString();
        $to = $this->to->toDateString();

        $timesheets = TeacherTimesheet::query()->whereIn('user_id', $userIds)->whereBetween('teaching_date', [$from, $to])
            ->where('status', '!=', 'invalid')->get(['user_id', 'teaching_date', 'late_minutes'])->groupBy('user_id');
        $checkins = StaffAttendance::query()->whereIn('user_id', $userIds)->whereBetween('work_date', [$from, $to])
            ->whereNotNull('check_in_at')->get(['user_id', 'work_date', 'late_minutes', 'late_excused'])->groupBy('user_id');
        $leaves = StaffAttendanceRequest::query()->approved()->where('type', StaffAttendanceRequest::TYPE_LEAVE)
            ->whereIn('user_id', $userIds)->overlapping($from, $to)->get(['user_id', 'date_from', 'date_to'])->groupBy('user_id');
        $violations = Penalty::query()->whereIn('user_id', $userIds)->whereBetween('violation_date', [$from, $to])
            ->where('status', '!=', 'cancelled')->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        $day = fn ($date) => Carbon::parse($date)->toDateString();

        return collect($userIds)->mapWithKeys(function (int $id) use ($timesheets, $checkins, $leaves, $violations, $day) {
            $sheets = $timesheets->get($id, collect());
            $ins = $checkins->get($id, collect());
            $lateSheetDays = $sheets->filter(fn ($t) => (int) $t->late_minutes > 0)->map(fn ($t) => $day($t->teaching_date));
            $lateCheckins = $ins->filter(fn ($a) => (int) $a->late_minutes > 0 && ! $a->late_excused)
                ->reject(fn ($a) => $lateSheetDays->contains($day($a->work_date)));

            return [$id => [
                'work_days' => $sheets->map(fn ($t) => $day($t->teaching_date))->merge($ins->map(fn ($a) => $day($a->work_date)))->unique()->count(),
                'late' => $lateSheetDays->count() + $lateCheckins->count(),
                'leave_days' => (int) $leaves->get($id, collect())->sum(fn ($r) => $this->daysInMonth($r->date_from, $r->date_to)),
                'violations' => (int) ($violations[$id] ?? 0),
            ]];
        })->all();
    }

    private function daysInMonth(Carbon $from, Carbon $to): int
    {
        $start = $from->copy()->max($this->from)->startOfDay();
        $end = $to->copy()->min($this->to)->startOfDay();

        return $end->lt($start) ? 0 : (int) $start->diffInDays($end) + 1;
    }

    /**
     * Tổng quan của chính giáo viên / trợ giảng: thẻ số liệu cá nhân + từng lớp.
     *
     * @return array<string, mixed>
     */
    public function forStaff(User $user): array
    {
        $classes = $this->classMetrics($this->classesOf($user));
        $work = $this->workStats([$user->id])[$user->id];

        return [
            'month' => $this->monthKey(),
            'monthLabel' => $this->monthLabel(),
            'work' => $work,
            'totals' => $this->totals($classes),
            'classes' => $classes,
        ];
    }

    /**
     * Bảng chất lượng giảng dạy theo giáo viên (GV chính của lớp) trong phạm vi lớp người xem thấy được.
     *
     * @return array<string, mixed>
     */
    public function teachersTable(User $viewer): array
    {
        // Lọc theo tên vai trò thay vì User::role(): vai trò giáo viên đã bị xóa trên hệ thống (vd. "Giáo viên giảng dạy")
        // thì User::role() ném RoleDoesNotExist → Tổng quan lỗi 500.
        $teacherIds = User::whereHas('roles', fn ($q) => $q->whereIn('name', StaffType::TEACHER_ROLES))->pluck('id');
        $classes = ClassModel::query()->visibleTo($viewer)->where('status', 'active')->whereIn('teacher_id', $teacherIds)
            ->orderBy('name')->get(['id', 'code', 'name', 'branch_id', 'teacher_id', 'status']);
        $metrics = collect($this->classMetrics($classes));
        $ids = $classes->pluck('teacher_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $work = $this->workStats($ids);
        $names = User::whereIn('id', $ids)->pluck('name', 'id');

        $rows = collect($ids)->map(function (int $id) use ($metrics, $work, $names) {
            $own = $metrics->where('teacher_id', $id)->values()->all();

            return ['id' => $id, 'name' => $names[$id] ?? '—', ...$this->totals($own), ...$work[$id]];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'month' => $this->monthKey(),
            'monthLabel' => $this->monthLabel(),
            'rows' => $rows,
        ];
    }

    /**
     * Cộng dồn nhiều lớp: tỷ lệ là trung bình có trọng số theo sĩ số (lớp chưa có dữ liệu không tính).
     *
     * @param  list<array<string, mixed>>  $classes
     * @return array{classes: int, students: int, new: int, dropped: int, attendance: ?float, homework: ?float, score: ?float}
     */
    public function totals(array $classes): array
    {
        $rows = collect($classes);
        $weighted = function (string $key) use ($rows): ?float {
            $with = $rows->filter(fn ($c) => $c[$key] !== null);
            $weight = $with->sum(fn ($c) => max(1, $c['students']));

            return $with->isEmpty() ? null : round($with->sum(fn ($c) => $c[$key] * max(1, $c['students'])) / $weight, 1);
        };

        return [
            'classes' => $rows->count(),
            'students' => $rows->sum('students'),
            'new' => $rows->sum('new'),
            'dropped' => $rows->sum('dropped'),
            'attendance' => $weighted('attendance'),
            'homework' => $weighted('homework'),
            'score' => $weighted('score'),
        ];
    }
}

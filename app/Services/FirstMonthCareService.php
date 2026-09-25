<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\ClassEnrollment;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\WorkTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Chăm sóc học viên tháng đầu — 3 mốc khớp gate hoa hồng A6 ("tick đủ 3/3 mốc chăm sóc: buổi 1, buổi 4–5, đủ 30 ngày"):
 *  - Buổi 1: sau buổi học đầu tiên học viên có mặt (có mặt / đi muộn).
 *  - Buổi 4–5: sau buổi có mặt thứ 4 (liên hệ trong khoảng buổi 4–5).
 *  - Đủ 30 ngày: 30 ngày sau ngày chốt (CRM `converted_at`; học viên không qua CRM: ngày xếp lớp sớm nhất, rồi ngày tạo hồ sơ).
 * Mỗi mốc tạo 1 việc (WorkTask) cho Học vụ chi nhánh (idempotent theo `work_tasks.student_id + care_milestone`).
 * Mốc được tick khi việc hoàn thành HOẶC CM tick mục tương ứng ở checklist CRM (CrmCustomer::CARE_CHECKLIST_ITEMS).
 */
class FirstMonthCareService
{
    /** Mã mốc (lưu ở work_tasks.care_milestone) => khóa checklist CRM. */
    public const MILESTONES = [
        self::MILESTONE_SESSION_1 => 'session_1',
        self::MILESTONE_SESSION_4_5 => 'session_4_5',
        self::MILESTONE_DAY_30 => 'day_30',
    ];

    public const MILESTONE_SESSION_1 = 1;

    public const MILESTONE_SESSION_4_5 = 4;

    public const MILESTONE_DAY_30 = 30;

    /** Nhãn ngắn của mốc (gate hoa hồng A6). */
    public const MILESTONE_SHORT_LABELS = [
        self::MILESTONE_SESSION_1 => 'Buổi 1',
        self::MILESTONE_SESSION_4_5 => 'Buổi 4–5',
        self::MILESTONE_DAY_30 => 'Đủ 30 ngày',
    ];

    /** Số ngày sau ngày chốt của mốc "Đủ 30 ngày". */
    public const DAYS_AFTER_CLOSING = 30;

    /**
     * Mốc chỉ được tạo khi sự kiện kích hoạt (buổi có mặt / ngày đủ 30 ngày) nằm trong N ngày gần nhất:
     * đủ rộng cho điểm danh bù (tối đa 30 ngày) và lệnh bị lỡ vài ngày, nhưng không tạo việc cho học viên học từ lâu.
     */
    public const CATCH_UP_DAYS = 30;

    /** Học viên đang học mới được chăm sóc tháng đầu. */
    public const ELIGIBLE_STATUSES = ['studying'];

    public static function milestoneLabel(int $milestone): string
    {
        $item = self::MILESTONES[$milestone] ?? null;

        return $item ? (CrmCustomer::CARE_CHECKLIST_ITEMS[$item] ?? $item) : "Mốc {$milestone}";
    }

    public static function milestoneShortLabel(int $milestone): string
    {
        return self::MILESTONE_SHORT_LABELS[$milestone] ?? "Mốc {$milestone}";
    }

    /**
     * Ngày bắt đầu học của nhiều học viên: buổi có mặt/đi muộn đầu tiên, chưa có thì ngày xếp lớp
     * (lượt xếp lớp còn hiệu lực sớm nhất).
     *
     * @param  array<int>  $studentIds
     * @return array<int, Carbon>
     */
    public function startDates(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $firstAttended = $this->attendedDates($studentIds)->map(fn (Collection $dates) => $dates->first());
        $firstEnrolled = $this->firstEnrollmentDates($studentIds);

        $dates = [];
        foreach ($studentIds as $id) {
            $value = $firstAttended[$id] ?? $firstEnrolled[$id] ?? null;
            if ($value) {
                $dates[$id] = Carbon::parse($value)->startOfDay();
            }
        }

        return $dates;
    }

    public function startDate(Student $student): ?Carbon
    {
        return $this->startDates([(int) $student->id])[(int) $student->id] ?? null;
    }

    /**
     * Ngày chốt của học viên: ngày chuyển đổi của khách CRM (mới nhất) → ngày xếp lớp sớm nhất → ngày tạo hồ sơ.
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, Carbon>
     */
    public function closingDates(Collection $students): array
    {
        $ids = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }
        $converted = CrmCustomer::whereIn('converted_student_id', $ids)->whereNotNull('converted_at')
            ->orderBy('id')->get(['converted_student_id', 'converted_at'])
            ->mapWithKeys(fn ($c) => [(int) $c->converted_student_id => $c->converted_at]);
        $enrolled = $this->firstEnrollmentDates($ids);

        $dates = [];
        foreach ($students as $student) {
            $value = $converted[(int) $student->id] ?? $enrolled[(int) $student->id] ?? $student->created_at;
            if ($value) {
                $dates[(int) $student->id] = Carbon::parse($value)->startOfDay();
            }
        }

        return $dates;
    }

    /**
     * Ngày kích hoạt từng mốc của học viên (null = chưa tới): Buổi 1 = ngày buổi có mặt đầu tiên, Buổi 4–5 = ngày buổi
     * có mặt thứ 4, Đủ 30 ngày = ngày chốt + 30.
     *
     * @return array<int, ?Carbon>
     */
    private function triggerDates(?Collection $attended, ?Carbon $closing): array
    {
        $attended = $attended ?? collect();

        return [
            self::MILESTONE_SESSION_1 => $attended->has(0) ? Carbon::parse($attended[0])->startOfDay() : null,
            self::MILESTONE_SESSION_4_5 => $attended->has(3) ? Carbon::parse($attended[3])->startOfDay() : null,
            self::MILESTONE_DAY_30 => $closing?->copy()->addDays(self::DAYS_AFTER_CLOSING),
        ];
    }

    /** Hạn việc chăm sóc: mốc theo buổi → ngày hôm sau buổi đó; mốc 30 ngày → đúng ngày đủ 30 ngày. */
    private function dueDate(int $milestone, Carbon $trigger): Carbon
    {
        return $milestone === self::MILESTONE_DAY_30 ? $trigger->copy() : $trigger->copy()->addDay();
    }

    /**
     * Tạo việc chăm sóc cho các mốc đã tới tính đến ngày $date. Idempotent (mỗi học viên + mốc chỉ 1 việc, kể cả
     * việc đã xóa mềm).
     *
     * @return array{created: int, skipped: array<string>}
     */
    public function run(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        $created = 0;
        $skipped = [];

        $students = Student::with('currentClass')
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->get();
        $ids = $students->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attended = $this->attendedDates($ids, $date);
        $closings = $this->closingDates($students);
        $existing = WorkTask::withTrashed()->whereIn('student_id', $ids)->whereNotNull('care_milestone')
            ->get(['student_id', 'care_milestone'])
            ->map(fn ($t) => $t->student_id.'-'.(int) $t->care_milestone)->flip();

        foreach ($students as $student) {
            $triggers = $this->triggerDates($attended[(int) $student->id] ?? null, $closings[(int) $student->id] ?? null);

            foreach ($triggers as $milestone => $trigger) {
                if (! $trigger || $trigger->greaterThan($date) || $trigger->diffInDays($date) > self::CATCH_UP_DAYS
                    || $existing->has($student->id.'-'.$milestone)) {
                    continue;
                }

                $branchId = $student->branch_id ?: $student->currentClass?->branch_id;
                $assignee = BranchStaff::academicStaff($branchId)->first()
                    ?? BranchStaff::withRoles('manager', $branchId)->first();
                if (! $assignee) {
                    $skipped[] = "{$student->name}: chi nhánh chưa có Học vụ";

                    continue;
                }
                $creator = BranchStaff::withRoles('manager', $branchId)->first()
                    ?? BranchStaff::admins()->first()
                    ?? $assignee;
                $short = self::milestoneShortLabel($milestone);

                $task = WorkTask::create([
                    'title' => "Chăm sóc tháng đầu ({$short}): {$student->name}",
                    'description' => self::milestoneLabel($milestone).'. Học viên '.$student->name
                        .($student->code ? " ({$student->code})" : '')
                        .($student->currentClass ? ', lớp '.$student->currentClass->name : '')
                        .($student->parent_phone ? ', SĐT phụ huynh '.$student->parent_phone : ($student->phone ? ', SĐT '.$student->phone : ''))
                        .'. '.($milestone === self::MILESTONE_DAY_30 ? 'Đủ 30 ngày từ ngày chốt' : 'Buổi có mặt').' ngày '
                        .($milestone === self::MILESTONE_DAY_30 ? $closings[(int) $student->id]->format('d/m/Y') : $trigger->format('d/m/Y')).'.',
                    'creator_id' => $creator->id,
                    'assignee_id' => $assignee->id,
                    'branch_id' => $branchId,
                    'class_id' => $student->current_class_id,
                    'student_id' => $student->id,
                    'care_milestone' => $milestone,
                    'time_slot_category' => 'after',
                    'task_type' => 'one_time',
                    'due_date' => $this->dueDate($milestone, $trigger)->toDateString(),
                    'status' => 'new',
                ]);
                $existing->put($student->id.'-'.$milestone, true);

                AdminNotification::create([
                    'user_id' => $assignee->id,
                    'type' => 'work_task_assigned',
                    'title' => 'Việc chăm sóc học viên tháng đầu',
                    'message' => $task->title.' — '.self::milestoneLabel($milestone),
                    'data' => ['link' => route('students.show', $student->id), 'task_id' => $task->id],
                    'is_read' => false,
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Checklist tháng đầu hiển thị ở hồ sơ học viên: mỗi mốc kèm ngày kích hoạt/hạn, việc đã tạo và trạng thái mục
     * tương ứng bên CRM (nếu học viên được chốt từ CRM).
     *
     * @return array{start: ?Carbon, closing: ?Carbon, customer: ?CrmCustomer, items: Collection, completed: int}
     */
    public function checklist(Student $student): array
    {
        $start = $this->startDate($student);
        $closing = $this->closingDates(collect([$student]))[(int) $student->id] ?? null;
        $customer = $this->customerFor($student);
        $crmState = (array) ($customer?->care_checklist ?? []);
        $tasks = WorkTask::with('assignee')->where('student_id', $student->id)->whereNotNull('care_milestone')
            ->get()->keyBy(fn ($t) => (int) $t->care_milestone);
        $triggers = $this->triggerDates($this->attendedDates([(int) $student->id])[(int) $student->id] ?? null, $closing);

        $items = collect(self::MILESTONES)->map(function (string $item, int $milestone) use ($crmState, $tasks, $triggers) {
            $task = $tasks->get($milestone);
            $trigger = $triggers[$milestone];

            return [
                'milestone' => $milestone,
                'key' => $item,
                'short' => self::milestoneShortLabel($milestone),
                'label' => self::milestoneLabel($milestone),
                'trigger' => $trigger,
                'due' => $task?->due_date ?? ($trigger ? $this->dueDate($milestone, $trigger) : null),
                'task' => $task,
                'done' => ($task && $task->status === 'completed') || ! empty($crmState[$item]),
                'crm_done' => $crmState[$item] ?? null,
            ];
        })->values();

        return [
            'start' => $start,
            'closing' => $closing,
            'customer' => $customer,
            'items' => $items,
            'completed' => $items->where('done', true)->count(),
        ];
    }

    /**
     * Số mốc chăm sóc tháng đầu đã tick (0–3) — dùng cho gate hoa hồng Phase 3 (A6: đủ 3/3). Một mốc được tính khi việc
     * chăm sóc của mốc đã hoàn thành hoặc mục checklist CRM tương ứng đã tick.
     */
    public function careMilestonesCompleted(CrmCustomer|Student $subject): int
    {
        if ($subject instanceof CrmCustomer) {
            $customer = $subject;
            $studentId = $subject->converted_student_id ? (int) $subject->converted_student_id : null;
        } else {
            $studentId = (int) $subject->id;
            $customer = $this->customerFor($subject);
        }

        $crmState = (array) ($customer?->care_checklist ?? []);
        $completedTasks = $studentId
            ? WorkTask::where('student_id', $studentId)->whereIn('care_milestone', array_keys(self::MILESTONES))
                ->where('status', 'completed')->pluck('care_milestone')->map(fn ($m) => (int) $m)->all()
            : [];

        return collect(self::MILESTONES)
            ->filter(fn (string $item, int $milestone) => ! empty($crmState[$item]) || in_array($milestone, $completedTasks, true))
            ->count();
    }

    /** Việc chăm sóc hoàn thành → đánh dấu mục checklist tương ứng bên CRM. */
    public function syncCompletedTask(WorkTask $task): void
    {
        $item = self::MILESTONES[(int) $task->care_milestone] ?? null;
        if (! $item || ! $task->student_id) {
            return;
        }

        $customer = CrmCustomer::where('converted_student_id', $task->student_id)->latest('id')->first();
        if (! $customer) {
            return;
        }

        $state = (array) ($customer->care_checklist ?? []);
        if (! empty($state[$item])) {
            return;
        }
        $state[$item] = [
            'done_at' => ($task->completed_at ?? now())->toDateTimeString(),
            'by' => $task->assignee?->name ?? 'Hệ thống',
        ];
        $customer->update(['care_checklist' => $state]);
    }

    private function customerFor(Student $student): ?CrmCustomer
    {
        return CrmCustomer::where('converted_student_id', $student->id)->latest('id')->first();
    }

    /**
     * Ngày các buổi học viên có mặt (có mặt / đi muộn), tăng dần, tính đến $until (nếu có).
     *
     * @param  array<int>  $studentIds
     * @return Collection<int, Collection<int, string>>
     */
    private function attendedDates(array $studentIds, ?Carbon $until = null): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return StudentAttendance::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['present', 'late'])
            ->when($until, fn ($q) => $q->whereDate('session_date', '<=', $until->toDateString()))
            ->orderBy('session_date')
            ->get(['student_id', 'session_date'])
            ->groupBy(fn ($a) => (int) $a->student_id)
            ->map(fn (Collection $rows) => $rows->map(fn ($a) => Carbon::parse($a->session_date)->toDateString())->values());
    }

    /**
     * @param  array<int>  $studentIds
     * @return Collection<int, string>
     */
    private function firstEnrollmentDates(array $studentIds): Collection
    {
        return ClassEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)
            ->whereNotNull('enrolled_at')
            ->selectRaw('student_id, MIN(enrolled_at) as first_date')
            ->groupBy('student_id')
            ->pluck('first_date', 'student_id');
    }
}

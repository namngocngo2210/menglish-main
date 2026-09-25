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
 * Chăm sóc học viên tháng đầu (BPMN bước 14): vào ngày thứ 3 / 7 / 14 / 30 kể từ khi học viên
 * bắt đầu học (buổi có mặt đầu tiên, chưa có thì ngày xếp lớp), tạo việc (WorkTask) cho Học vụ
 * chi nhánh. Mỗi mốc gắn với một mục checklist chăm sóc tháng đầu của CRM
 * (CrmCustomer::CARE_CHECKLIST_ITEMS) — hoàn thành việc thì tự đánh dấu mục đó bên CRM.
 */
class FirstMonthCareService
{
    /** Mốc (số ngày sau ngày bắt đầu) => mục checklist CRM. */
    public const MILESTONES = [
        3 => 'first_session_feedback',
        7 => 'materials_check',
        14 => 'week2_parent_update',
        30 => 'month_end_review',
    ];

    /** Lệnh bị lỡ vài ngày (server dừng) vẫn tạo bù mốc đến hạn trong khoảng này. */
    public const CATCH_UP_DAYS = 7;

    /** Học viên đang học mới được chăm sóc tháng đầu. */
    public const ELIGIBLE_STATUSES = ['studying'];

    public static function milestoneLabel(int $day): string
    {
        $item = self::MILESTONES[$day] ?? null;

        return $item ? (CrmCustomer::CARE_CHECKLIST_ITEMS[$item] ?? $item) : "Mốc ngày {$day}";
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

        $firstAttended = StudentAttendance::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ['present', 'late'])
            ->selectRaw('student_id, MIN(session_date) as first_date')
            ->groupBy('student_id')
            ->pluck('first_date', 'student_id');
        $firstEnrolled = ClassEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)
            ->whereNotNull('enrolled_at')
            ->selectRaw('student_id, MIN(enrolled_at) as first_date')
            ->groupBy('student_id')
            ->pluck('first_date', 'student_id');

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
     * Tạo việc chăm sóc đến hạn tại ngày $date. Idempotent (mỗi học viên + mốc chỉ 1 việc, kể cả
     * việc đã xóa mềm).
     *
     * @return array{created: int, skipped: array<string>}
     */
    public function run(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        $maxDay = max(array_keys(self::MILESTONES));
        $created = 0;
        $skipped = [];

        $students = Student::with('currentClass')
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->get();
        $starts = $this->startDates($students->pluck('id')->map(fn ($id) => (int) $id)->all());

        foreach ($students as $student) {
            $start = $starts[(int) $student->id] ?? null;
            if (! $start || $start->greaterThan($date) || $start->diffInDays($date) > $maxDay + self::CATCH_UP_DAYS) {
                continue;
            }

            foreach (array_keys(self::MILESTONES) as $day) {
                $due = $start->copy()->addDays($day);
                if ($due->greaterThan($date) || $due->diffInDays($date) > self::CATCH_UP_DAYS) {
                    continue;
                }
                if (WorkTask::withTrashed()->where('student_id', $student->id)->where('care_milestone', $day)->exists()) {
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

                $task = WorkTask::create([
                    'title' => "Chăm sóc tháng đầu (ngày {$day}): {$student->name}",
                    'description' => self::milestoneLabel($day).'. Học viên '.$student->name
                        .($student->code ? " ({$student->code})" : '')
                        .($student->currentClass ? ', lớp '.$student->currentClass->name : '')
                        .($student->phone ? ', SĐT '.$student->phone : '')
                        .'. Bắt đầu học ngày '.$start->format('d/m/Y').'.',
                    'creator_id' => $creator->id,
                    'assignee_id' => $assignee->id,
                    'branch_id' => $branchId,
                    'class_id' => $student->current_class_id,
                    'student_id' => $student->id,
                    'care_milestone' => $day,
                    'time_slot_category' => 'after',
                    'task_type' => 'one_time',
                    'due_date' => $due->toDateString(),
                    'status' => 'new',
                ]);

                AdminNotification::create([
                    'user_id' => $assignee->id,
                    'type' => 'work_task_assigned',
                    'title' => 'Việc chăm sóc học viên tháng đầu',
                    'message' => $task->title.' — '.self::milestoneLabel($day),
                    'data' => ['link' => route('students.show', $student->id), 'task_id' => $task->id],
                    'is_read' => false,
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Checklist tháng đầu hiển thị ở hồ sơ học viên: mỗi mốc kèm hạn, việc đã tạo và trạng thái
     * mục tương ứng bên CRM (nếu học viên được chốt từ CRM).
     *
     * @return array{start: ?Carbon, customer: ?CrmCustomer, items: Collection}
     */
    public function checklist(Student $student): array
    {
        $start = $this->startDate($student);
        $customer = CrmCustomer::where('converted_student_id', $student->id)->first();
        $crmState = (array) ($customer?->care_checklist ?? []);
        $tasks = WorkTask::with('assignee')->where('student_id', $student->id)->whereNotNull('care_milestone')
            ->get()->keyBy('care_milestone');

        $items = collect(self::MILESTONES)->map(function (string $item, int $day) use ($start, $crmState, $tasks) {
            $task = $tasks->get($day);

            return [
                'day' => $day,
                'label' => self::milestoneLabel($day),
                'due' => $start?->copy()->addDays($day),
                'task' => $task,
                'done' => ($task && $task->status === 'completed') || ! empty($crmState[$item]),
                'crm_done' => $crmState[$item] ?? null,
            ];
        })->values();

        return ['start' => $start, 'customer' => $customer, 'items' => $items];
    }

    /** Việc chăm sóc hoàn thành → đánh dấu mục checklist tương ứng bên CRM. */
    public function syncCompletedTask(WorkTask $task): void
    {
        $item = self::MILESTONES[(int) $task->care_milestone] ?? null;
        if (! $item || ! $task->student_id) {
            return;
        }

        $customer = CrmCustomer::where('converted_student_id', $task->student_id)->first();
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
}

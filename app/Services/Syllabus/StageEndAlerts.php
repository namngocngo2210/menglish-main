<?php

namespace App\Services\Syllabus;

use App\Models\SyllabusAssignment;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\NotificationService;
use App\Support\DataScope;

/**
 * Sắp hết chặng → việc cho Học thuật (docs/kho-syllabus.md §9.2, Bước 8–9): chặng đang mở còn ≤ N buổi syllabus chưa dạy (N =
 * báo trước của chặng, mặc định 6) hoặc buổi hiện tại đã tới buổi có mốc "Báo Học thuật chuẩn bị" → 1 việc / chặng của lớp,
 * hạn = ngày dự kiến buổi cuối chặng, thông báo NHẬN VIỆC. Idempotent.
 */
class StageEndAlerts
{
    private const DEFAULT_NOTICE = 6;

    public function __construct(private readonly ClassSyllabus $syllabus, private readonly SyllabusNotifier $notifier) {}

    public function run(): int
    {
        $created = 0;
        SyllabusAssignment::open()->with(['classModel', 'stage', 'curriculum:id,title'])
            ->whereNotNull('stage_id')->whereNotNull('class_id')
            ->whereDoesntHave('tasks', fn ($q) => $q->where('kind', WorkTask::KIND_SYLLABUS_STAGE_END))
            ->lazyById()
            ->each(function (SyllabusAssignment $assignment) use (&$created) {
                if ($this->due($assignment) && ($task = $this->createTask($assignment))) {
                    $this->notifier->stageEnding($task);
                    $created++;
                }
            });

        return $created;
    }

    private function due(SyllabusAssignment $assignment): bool
    {
        $timeline = $this->syllabus->timeline($assignment);
        $rows = $timeline['rows']->values();
        if ($rows->isEmpty()) {
            return false;
        }
        $remaining = $rows->count() - $timeline['taught'];
        $notice = (int) ($assignment->stage?->notice_before_sessions ?: self::DEFAULT_NOTICE);
        $currentIndex = $timeline['current'] ? $rows->search(fn ($r) => $r['lesson']->id === $timeline['current']['lesson']->id) : $rows->count();
        $milestoneIndex = $rows->search(fn ($r) => ($r['data']['notice_milestone'] ?? null) === 'notify_academic');

        return $remaining <= $notice || ($milestoneIndex !== false && $currentIndex >= $milestoneIndex);
    }

    private function createTask(SyllabusAssignment $assignment): ?WorkTask
    {
        $class = $assignment->classModel;
        $assignee = $this->assignee($assignment);
        if (! $class || ! $assignee) {
            return null;
        }
        $rows = $this->syllabus->timeline($assignment)['rows'];
        $due = $rows->last()['planned_date'] ?? $assignment->deadline ?? today()->addDays(7);
        $stage = $assignment->stage?->label ?? $assignment->stage_name;

        return WorkTask::create([
            'title' => "Chuẩn bị test {$stage} – giáo trình {$assignment->curriculum?->title} và syllabus mới cho lớp {$class->name}",
            'description' => "Lớp {$class->name} sắp hết {$stage}: chuẩn bị đề Big Test cuối chặng và syllabus chặng tiếp theo, rồi giao chặng tiếp cho lớp.",
            'creator_id' => $assignment->opened_by ?: $assignee->id,
            'assignee_id' => $assignee->id,
            'branch_id' => $class->branch_id,
            'class_id' => $class->id,
            'kind' => WorkTask::KIND_SYLLABUS_STAGE_END,
            'syllabus_assignment_id' => $assignment->id,
            'task_type' => 'one_time',
            'time_slot_category' => 'after',
            'due_date' => $due->toDateString(),
            'status' => 'new',
        ]);
    }

    /** Người giao chặng (còn quyền), không thì Học thuật phụ trách chi nhánh lớp, không thì Học thuật / Admin bất kỳ (câu 10). */
    private function assignee(SyllabusAssignment $assignment): ?User
    {
        $opener = $assignment->opened_by ? User::where('is_active', true)->find($assignment->opened_by) : null;
        if ($opener?->can('syllabus.assign')) {
            return $opener;
        }
        $candidates = User::whereIn('id', NotificationService::userIdsWithPermission('syllabus.assign'))->orderBy('id')->get();

        return $candidates->first(fn (User $u) => DataScope::coversBranch($u, 'class', $assignment->classModel?->branch_id)) ?? $candidates->first();
    }
}

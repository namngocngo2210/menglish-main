<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\Penalty;
use App\Models\WorkTask;
use App\Services\FirstMonthCareService;
use App\Support\Money;
use App\Support\StaffType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mark new, in-progress and blocked tasks past their deadline as overdue and notify the assignee once.
 * Blocked tasks never generate task-overdue penalties. Pending confirmation and TA daily tasks without deadlines are excluded.
 * First-month care SLA penalties are handled separately by FirstMonthCareService::enforceSla.
 * Idempotent: rerunning does not create additional penalties or notifications.
 */
class MarkOverdueTasksCommand extends Command
{
    protected $signature = 'tasks:mark-overdue';

    protected $description = 'Đánh dấu "Quá hạn" cho công việc chưa làm xong đã qua hạn';

    public function handle(FirstMonthCareService $care): int
    {
        $now = now();
        $count = 0;

        WorkTask::query()
            ->withDeadline() // Nhiệm vụ hằng ngày của TA (CV-05) không có hạn → không quét quá hạn.
            ->whereIn('status', [...WorkTask::OPEN_STATUSES, 'blocked'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $now->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($tasks) use ($now, &$count) {
                foreach ($tasks as $task) {
                    if ($task->lateHours($now) <= 0) {
                        continue;
                    }
                    $originalStatus = $task->status;
                    $task->update(['status' => 'overdue']);
                    $count++;
                    if ($originalStatus !== 'blocked') {
                        $this->penalize($task);
                    }

                    if ($task->assignee_id) {
                        AdminNotification::create([
                            'user_id' => $task->assignee_id,
                            'type' => 'task_assigned',
                            'title' => "Công việc quá hạn: {$task->title}",
                            'message' => 'Hạn '.$task->dueAt()?->format('H:i d/m/Y').' — hãy hoàn thành hoặc báo lý do.',
                            'data' => ['task_id' => $task->id, 'link' => route('tasks.index', ['tab' => 'mine', 'status' => 'overdue'])],
                            'is_read' => false,
                        ]);
                    }
                }
            });

        $penalties = $care->enforceSla($now);

        $this->info("Đã chuyển {$count} công việc sang Quá hạn, lập {$penalties} biên bản quá SLA chăm sóc tháng đầu.");

        return self::SUCCESS;
    }

    /**
     * Create one final fined penalty per task, using its configured amount and payroll violation date.
     * Existing penalties, including cancelled ones, prevent duplicates. SLA/care tasks are handled separately.
     * Foreign teachers and academic roles are exempt; academic task timeliness only affects KPI.
     */
    private function penalize(WorkTask $task): void
    {
        if ($task->series_id !== null) {
            $series = $task->series()->first();
            $penalize = $series?->isConfigured() ?? false;
            $amount = (float) $series?->penalty_amount;
        } else {
            $penalize = $task->phat_khi_qua_han;
            $amount = (float) $task->muc_phat_qua_han;
        }
        $assignee = $task->assignee;
        if (! $penalize || $task->sla_rule_key || $task->care_milestone || $amount <= 0 || ! $assignee || StaffType::isForeignTeacher($assignee) || StaffType::isAcademicKpiOnly($assignee)) {
            return;
        }

        DB::transaction(function () use ($task, $amount) {
            if (Penalty::withoutViewerScope()->where('work_task_id', $task->id)->where('auto_source', 'task_overdue')->exists()) {
                return;
            }
            $penalty = Penalty::create([
                'code' => Penalty::generateCode(),
                'user_id' => $task->assignee_id,
                'class_id' => $task->class_id,
                'work_task_id' => $task->id,
                'auto_source' => 'task_overdue',
                'violation_type' => "Quá hạn công việc: {$task->title}",
                'error_category' => 'operations',
                'violation_date' => $task->due_date->toDateString(),
                'violation_at' => $task->dueAt(),
                'amount' => $amount,
                'reporter_id' => null,
                'status' => 'fined',
                'decided_at' => now(),
                'decision_note' => 'Tự động chốt phạt theo cấu hình "Phạt khi quá hạn" của việc.',
                'notes' => "Hạn {$task->dueAt()?->format('H:i d/m/Y')}, việc chưa hoàn thành.",
            ]);

            AdminNotification::create([
                'user_id' => $task->assignee_id,
                'type' => 'penalty_fined',
                'title' => "Biên bản {$penalty->code}: phạt ".Money::format($amount),
                'message' => "Công việc \"{$task->title}\" quá hạn — phạt trừ vào lương kỳ này.",
                'data' => ['penalty_id' => $penalty->id, 'task_id' => $task->id, 'link' => route('penalties.index', ['search' => $penalty->code])],
                'is_read' => false,
            ]);
        });
    }
}

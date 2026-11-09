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
 * Chuyển công việc "Mới" / "Đang thực hiện" đã qua hạn (ngày + giờ hạn) sang "Quá hạn"
 * (mockup Danh sách công việc / Nhiệm vụ hôm nay TA có trạng thái "Quá hạn — Trễ N giờ").
 * Báo người thực hiện một lần. Việc "Bị chặn" / "Chờ xác nhận" và nhiệm vụ hằng ngày của TA (kind = ta_daily, không có hạn) không bị đổi.
 * Việc chăm sóc tháng đầu quá hạn → biên bản vi phạm SLA cho người được giao (FirstMonthCareService::enforceSla).
 * Idempotent: chạy lại không đổi gì thêm.
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
            ->whereIn('status', WorkTask::OPEN_STATUSES)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $now->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($tasks) use ($now, &$count) {
                foreach ($tasks as $task) {
                    if ($task->lateHours($now) <= 0) {
                        continue;
                    }
                    $task->update(['status' => 'overdue']);
                    $count++;
                    $this->penalize($task);

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
     * Việc giao tay bật "Phạt khi quá hạn" (TK-2026-0078): lập biên bản "Đã chốt phạt" (fined) đúng số tiền đã nhập →
     * bảng lương trừ theo Penalty::scopeDeductibleFor. Một lần / việc (kiểm tra theo biên bản đã có, kể cả đã hủy).
     * Việc SLA (sla_rule_key) do SlaBreachService / FirstMonthCareService lập biên bản theo Cấu hình SLA → bỏ qua ở đây.
     * Giao tay chưa có bậc phạt: một mức cố định cho mỗi việc. GVNN không bị phạt (như SLA).
     */
    private function penalize(WorkTask $task): void
    {
        $amount = (float) $task->muc_phat_qua_han;
        $assignee = $task->assignee;
        if (! $task->phat_khi_qua_han || $task->sla_rule_key || $task->care_milestone || $amount <= 0 || ! $assignee || StaffType::isForeignTeacher($assignee)) {
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
                'due_date' => today()->addDays(Penalty::paymentDueDays())->toDateString(),
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

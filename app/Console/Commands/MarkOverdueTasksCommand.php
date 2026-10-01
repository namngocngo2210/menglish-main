<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\WorkTask;
use App\Services\FirstMonthCareService;
use Illuminate\Console\Command;

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
}

<?php

namespace App\Console\Commands;

use App\Models\WorkTask;
use App\Services\Syllabus\SyllabusNotifier;
use Illuminate\Console\Command;

/**
 * Việc cuối chặng quá hạn → nhắc người làm + Quản lý chi nhánh mỗi ngày 1 lần (docs/kho-syllabus.md §9.2, đề xuất Bước 9).
 * Idempotent trong ngày (last_reminded_on).
 */
class RemindOverdueSyllabusTasksCommand extends Command
{
    protected $signature = 'syllabus:remind-overdue-tasks';

    protected $description = 'Nhắc hằng ngày việc chuẩn bị test của Học thuật đã quá hạn';

    public function handle(SyllabusNotifier $notifier): int
    {
        $count = 0;
        WorkTask::query()->where('kind', WorkTask::KIND_SYLLABUS_STAGE_END)
            ->whereIn('status', [...WorkTask::OPEN_STATUSES, 'overdue'])
            ->whereDate('due_date', '<', today())
            ->where(fn ($q) => $q->whereNull('last_reminded_on')->orWhereDate('last_reminded_on', '<', today()))
            ->lazyById()
            ->each(function (WorkTask $task) use ($notifier, &$count) {
                $task->update(['last_reminded_on' => today()]);
                $notifier->taskOverdue($task);
                $count++;
            });
        $this->info("Đã nhắc {$count} việc quá hạn.");

        return self::SUCCESS;
    }
}

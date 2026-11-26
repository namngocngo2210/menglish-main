<?php

namespace App\Console\Commands;

use App\Models\WorkTaskSeries;
use Illuminate\Console\Command;

/**
 * Safety net for recurring work: any configured series with no open occurrence gets its next period spawned via
 * WorkTaskSeries::resume() (same rules as completion / penalty configuration). Idempotent: an open occurrence blocks it.
 */
class SpawnRecurringTasksCommand extends Command
{
    protected $signature = 'tasks:spawn-recurring {--dry-run : Chỉ báo cáo, không tạo việc}';

    protected $description = 'Sinh kỳ kế tiếp cho việc lặp đã cấu hình phạt mà chưa có kỳ nào đang mở';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $spawned = [];

        WorkTaskSeries::query()
            ->whereNotNull('configured_at')
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNotIn('status', ['completed', 'canceled']))
            ->orderBy('id')
            ->each(function (WorkTaskSeries $series) use ($dry, &$spawned) {
                if ($dry) {
                    $spawned[$series->id] = '(sẽ tạo)';

                    return;
                }
                if ($task = $series->resume()) {
                    $spawned[$series->id] = "việc #{$task->id} hạn {$task->due_date->toDateString()}";
                }
            });

        $this->info(($dry ? '[DRY-RUN] Sẽ tạo ' : 'Đã tạo ').count($spawned).' kỳ kế tiếp cho việc lặp.');
        foreach ($spawned as $id => $info) {
            $this->line("  + Chuỗi #{$id}: {$info}");
        }

        return self::SUCCESS;
    }
}

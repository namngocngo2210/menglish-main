<?php

namespace App\Console\Commands;

use App\Services\Syllabus\StageEndAlerts;
use Illuminate\Console\Command;

/** Sắp hết chặng → tạo việc chuẩn bị test cho Học thuật (docs/kho-syllabus.md §9.2). Idempotent: 1 việc / chặng của lớp. */
class SyllabusStageEndAlertsCommand extends Command
{
    protected $signature = 'syllabus:stage-end-alerts';

    protected $description = 'Tạo việc chuẩn bị test cho Học thuật khi lớp sắp hết chặng';

    public function handle(StageEndAlerts $alerts): int
    {
        $this->info('Đã tạo '.$alerts->run().' việc cuối chặng.');

        return self::SUCCESS;
    }
}

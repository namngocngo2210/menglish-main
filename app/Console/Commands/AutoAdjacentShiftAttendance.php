<?php

namespace App\Console\Commands;

use App\Services\TeachingShifts\AdjacentShiftAttendance;
use Illuminate\Console\Command;

class AutoAdjacentShiftAttendance extends Command
{
    protected $signature = 'attendance:auto-adjacent-shifts';

    protected $description = 'Tự động ghi và dọn công ca liền kề cho giáo viên bán thời gian';

    public function handle(AdjacentShiftAttendance $attendance): int
    {
        $counts = $attendance->run();
        $this->info("Đã tạo {$counts['created']} công ca liền kề, đã gỡ {$counts['removed']} công nháp.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\FirstMonthCareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Chăm sóc học viên tháng đầu: tạo việc cho Học vụ chi nhánh ở ngày 3/7/14/30 sau khi học viên
 * bắt đầu học. Chạy hằng ngày, idempotent (chạy lại không tạo trùng).
 */
class ScheduleFirstMonthCareCommand extends Command
{
    protected $signature = 'students:schedule-first-month-care {--date= : Ngày xử lý Y-m-d}';

    protected $description = 'Tạo việc chăm sóc tháng đầu (ngày 3/7/14/30) cho Học vụ chi nhánh';

    public function handle(FirstMonthCareService $care): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $result = $care->run($date);

        $this->info("Đã tạo {$result['created']} việc chăm sóc tháng đầu.");
        foreach ($result['skipped'] as $line) {
            $this->warn('Bỏ qua: '.$line);
        }

        return self::SUCCESS;
    }
}

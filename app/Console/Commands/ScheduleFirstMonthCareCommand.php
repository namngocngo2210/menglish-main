<?php

namespace App\Console\Commands;

use App\Services\FirstMonthCareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Chăm sóc học viên tháng đầu (3 mốc gate hoa hồng A6): tạo việc cho Học vụ chi nhánh sau buổi có mặt đầu tiên,
 * sau buổi có mặt thứ 4 (Buổi 4–5) và khi đủ 30 ngày từ ngày chốt. Chạy hằng ngày, idempotent.
 */
class ScheduleFirstMonthCareCommand extends Command
{
    protected $signature = 'students:schedule-first-month-care {--date= : Ngày xử lý Y-m-d}';

    protected $description = 'Tạo việc chăm sóc tháng đầu (Buổi 1, Buổi 4–5, Đủ 30 ngày) cho Học vụ chi nhánh';

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

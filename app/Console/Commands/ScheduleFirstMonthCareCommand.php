<?php

namespace App\Console\Commands;

use App\Services\FirstMonthCareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Chăm sóc học viên tháng đầu (3 mốc gate hoa hồng A6: Buổi 1, Buổi 4–5, Đủ 30 ngày từ ngày chốt): giao việc cho Học vụ
 * chi nhánh ngay trong tháng đầu, đặt hạn (SLA) khi tới mốc. Chạy hằng giờ, idempotent.
 */
class ScheduleFirstMonthCareCommand extends Command
{
    protected $signature = 'students:schedule-first-month-care {--date= : Ngày xử lý Y-m-d}';

    protected $description = 'Giao việc chăm sóc tháng đầu (Buổi 1, Buổi 4–5, Đủ 30 ngày) cho Học vụ chi nhánh';

    public function handle(FirstMonthCareService $care): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $result = $care->run($date);

        $this->info("Đã giao {$result['created']} việc chăm sóc tháng đầu, đặt hạn {$result['scheduled']} việc tới mốc.");
        foreach ($result['skipped'] as $line) {
            $this->warn('Bỏ qua: '.$line);
        }

        return self::SUCCESS;
    }
}

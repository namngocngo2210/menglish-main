<?php

namespace App\Console\Commands;

use App\Services\Practice\PracticeAttemptService;
use Illuminate\Console\Command;

/** Chốt các lượt luyện tập đang làm đã quá hạn (học viên đóng trình duyệt giữa chừng); chạy lại không chấm trùng. */
class ClosePracticeAttemptsCommand extends Command
{
    protected $signature = 'practice:close-expired';

    protected $description = 'Tự nộp các lượt luyện tập đã hết giờ';

    public function handle(PracticeAttemptService $service): int
    {
        $count = $service->closeExpired();

        $this->info("Đã tự nộp {$count} lượt luyện tập quá hạn.");

        return self::SUCCESS;
    }
}

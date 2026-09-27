<?php

namespace App\Console\Commands;

use App\Services\Students\ClassStartActivation;
use Illuminate\Console\Command;

/**
 * Lớp tới ngày khai giảng → học viên "Chờ khai giảng" đã hoàn tất nhập học chuyển sang "Đang học".
 * Chạy hằng ngày; chạy lại nhiều lần không tạo thay đổi trùng.
 */
class StartStudyingCommand extends Command
{
    protected $signature = 'students:start-studying';

    protected $description = 'Chuyển học viên Chờ khai giảng sang Đang học khi lớp đã khai giảng';

    public function handle(ClassStartActivation $activation): int
    {
        $count = $activation->run();

        $this->info("Đã chuyển {$count} học viên sang Đang học.");

        return self::SUCCESS;
    }
}

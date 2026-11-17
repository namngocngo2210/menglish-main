<?php

namespace App\Console\Commands;

use App\Services\Students\ClassStartActivation;
use Illuminate\Console\Command;

/**
 * Start due classes and their waiting students daily, without requiring enrollment confirmation.
 * Repeated runs make no further changes.
 */
class StartStudyingCommand extends Command
{
    protected $signature = 'students:start-studying';

    protected $description = 'Khai giảng lớp tới ngày bắt đầu và chuyển học viên Chờ khai giảng sang Đang học';

    public function handle(ClassStartActivation $activation): int
    {
        $counts = $activation->run();

        $this->info("Đã khai giảng {$counts['classes_started']} lớp và chuyển {$counts['students_started']} học viên sang Đang học.");

        return self::SUCCESS;
    }
}

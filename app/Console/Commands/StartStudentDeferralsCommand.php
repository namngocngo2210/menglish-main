<?php

namespace App\Console\Commands;

use App\Services\StudentDeferralService;
use Illuminate\Console\Command;

/**
 * Tới ngày bắt đầu của bảo lưu đã duyệt trước → đóng băng số buổi / công nợ và chuyển học viên "Bảo lưu".
 * Chạy hằng ngày; chạy lại nhiều lần không tạo thay đổi trùng.
 */
class StartStudentDeferralsCommand extends Command
{
    protected $signature = 'students:start-deferrals';

    protected $description = 'Bắt đầu bảo lưu cho học viên tới ngày bắt đầu bảo lưu đã được duyệt';

    public function handle(StudentDeferralService $service): int
    {
        $started = 0;
        foreach ($service->startingStudents() as $student) {
            if ($service->start($student)) {
                $started++;
            }
        }

        $this->info("Đã bắt đầu bảo lưu cho {$started} học viên.");

        return self::SUCCESS;
    }
}

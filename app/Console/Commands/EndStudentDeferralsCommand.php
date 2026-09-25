<?php

namespace App\Console\Commands;

use App\Services\StudentDeferralService;
use Illuminate\Console\Command;

/**
 * Hết thời gian bảo lưu → tự chuyển học viên về "Đang học" / "Chờ khai giảng" và báo Học vụ.
 * Chạy hằng ngày; chạy lại nhiều lần không tạo thay đổi hay thông báo trùng.
 */
class EndStudentDeferralsCommand extends Command
{
    protected $signature = 'students:end-deferrals';

    protected $description = 'Kết thúc bảo lưu cho học viên đã hết thời gian bảo lưu và thông báo Học vụ';

    public function handle(StudentDeferralService $service): int
    {
        $ended = 0;
        foreach ($service->dueStudents() as $student) {
            if ($service->end($student, null, true) !== null) {
                $ended++;
            }
        }

        $this->info("Đã kết thúc bảo lưu cho {$ended} học viên.");

        return self::SUCCESS;
    }
}

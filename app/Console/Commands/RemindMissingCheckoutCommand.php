<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\StaffAttendance;
use Illuminate\Console\Command;

/**
 * Nhắc chấm công ra: cuối ngày, nhân sự đã chấm vào hôm nay mà chưa chấm ra → báo 1 lần (còn chấm ra được tới 06:00
 * sáng hôm sau, quá giờ đó gửi đơn "Bổ sung công"). Idempotent: mỗi ngày công nhận tối đa 1 lời nhắc.
 */
class RemindMissingCheckoutCommand extends Command
{
    protected $signature = 'staff-attendance:remind-checkout';

    protected $description = 'Nhắc nhân sự đã chấm vào hôm nay nhưng chưa chấm công ra';

    public function handle(): int
    {
        $created = 0;

        StaffAttendance::query()
            ->whereDate('work_date', today()->toDateString())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$created) {
                foreach ($rows as $attendance) {
                    $exists = AdminNotification::query()
                        ->where('type', 'staff_checkout_reminder')
                        ->where('user_id', $attendance->user_id)
                        ->where('data->attendance_id', $attendance->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    AdminNotification::create([
                        'user_id' => $attendance->user_id,
                        'type' => 'staff_checkout_reminder',
                        'title' => 'Bạn chưa chấm công ra hôm nay',
                        'message' => 'Đã chấm vào lúc '.$attendance->check_in_at->format('H:i').'. Chấm ra trước 06:00 sáng mai, quá giờ đó hãy gửi đơn "Bổ sung công".',
                        'data' => ['attendance_id' => $attendance->id, 'link' => route('my-attendance.home')],
                        'is_read' => false,
                    ]);
                    $created++;
                }
            });

        $this->info("Đã nhắc {$created} nhân sự chưa chấm công ra.");

        return self::SUCCESS;
    }
}

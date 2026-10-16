<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\User;
use App\Support\DailyReportDue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nhắc Học vụ / Học thuật nộp báo cáo ngày hôm qua (rà soát 16/10/2026 B3): chạy 08:00, trước hạn 09:00. Chỉ nhắc người phải nộp
 * hôm qua (không phải Chủ nhật, ngày lễ, ngày nghỉ phép đã duyệt) mà chưa nộp. Idempotent theo (người, ngày báo cáo).
 */
class RemindDailyReportsCommand extends Command
{
    protected $signature = 'reports:remind-daily';

    protected $description = 'Nhắc Học vụ / Học thuật nộp báo cáo ngày hôm qua (hạn 09:00 sáng nay)';

    public function handle(): int
    {
        $yesterday = Carbon::yesterday();
        $date = $yesterday->toDateString();
        $count = 0;

        DailyReportDue::writers()->each(function (User $user) use ($yesterday, $date, &$count) {
            if (DailyReportDue::missingDays($user, $yesterday, $yesterday)->isEmpty()) {
                return;
            }
            if (AdminNotification::where('user_id', $user->id)->where('type', 'daily_report_due')->where('data->date', $date)->exists()) {
                return;
            }
            AdminNotification::notifyUser($user->id, 'daily_report_due', 'Chưa nộp báo cáo ngày '.$yesterday->format('d/m'),
                'Hạn nộp '.DailyReportDue::deadline($yesterday)->format('H:i d/m').'. Nộp sau hạn tính là nộp trễ.',
                route('reports.periodic.daily', ['date' => $date]), ['date' => $date]);
            $count++;
        });

        $this->info("Đã nhắc {$count} người nộp báo cáo ngày {$yesterday->format('d/m')}.");

        return self::SUCCESS;
    }
}

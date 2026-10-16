<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\User;
use App\Services\BranchStaff;
use App\Support\DailyReportDue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Bản tổng hợp báo cáo ngày cho Admin mỗi sáng, sau hạn 09:00 (rà soát 16/10/2026 B12): thay cho thông báo từng báo cáo ngày.
 * "Đã nộp x/y" + tên người còn thiếu, link mở tab "Chưa nộp". Ngày không ai phải nộp (Chủ nhật, lễ) thì không gửi. Idempotent theo ngày.
 */
class DailyReportDigestCommand extends Command
{
    protected $signature = 'reports:daily-digest';

    protected $description = 'Gửi Admin bản tổng hợp báo cáo ngày hôm qua: đã nộp / còn thiếu';

    public function handle(): int
    {
        $yesterday = Carbon::yesterday();
        $date = $yesterday->toDateString();
        $due = DailyReportDue::writers()->filter(fn (User $u) => DailyReportDue::requiredDays($u, $yesterday, $yesterday)->isNotEmpty());
        if ($due->isEmpty()) {
            $this->info('Hôm qua không ai phải nộp báo cáo ngày.');

            return self::SUCCESS;
        }
        $missing = $due->filter(fn (User $u) => DailyReportDue::missingDays($u, $yesterday, $yesterday)->isNotEmpty());
        $submitted = $due->count() - $missing->count();

        $sent = 0;
        foreach (BranchStaff::admins()->pluck('id') as $adminId) {
            if (AdminNotification::where('user_id', $adminId)->where('type', 'staff_report_digest')->where('data->date', $date)->exists()) {
                continue;
            }
            AdminNotification::notifyUser($adminId, 'staff_report_digest',
                "Báo cáo ngày {$yesterday->format('d/m')}: đã nộp {$submitted}/{$due->count()}",
                $missing->isEmpty() ? 'Mọi người đã nộp đủ. Mở để kiểm tra.' : 'Chưa nộp: '.$missing->pluck('name')->implode(', ').'.',
                $missing->isEmpty()
                    ? route('reports.all', ['type' => 'daily', 'from' => $date, 'to' => $date])
                    : route('reports.all', ['view' => 'missing', 'from' => $date, 'to' => $date]),
                ['date' => $date]);
            $sent++;
        }

        $this->info("Đã gửi tổng hợp báo cáo ngày {$yesterday->format('d/m')} cho {$sent} Admin.");

        return self::SUCCESS;
    }
}

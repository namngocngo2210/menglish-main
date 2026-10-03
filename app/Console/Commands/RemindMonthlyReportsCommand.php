<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\StaffReport;
use App\Models\User;
use App\Support\MonthlyReportDue;
use App\Support\StaffType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nhắc nộp báo cáo giảng dạy tháng (GV / trợ giảng — kỳ báo cáo "monthly"): hạn là Chủ nhật cuối tháng;
 * nhắc vào 3 ngày trước, 1 ngày trước và đúng ngày hạn cho người CHƯA nộp báo cáo tháng này.
 * Chỉ nhắc, không có phạt. Idempotent theo (người, tháng, loại nhắc): chạy lại cùng ngày không tạo trùng.
 */
class RemindMonthlyReportsCommand extends Command
{
    protected $signature = 'reports:remind-monthly';

    protected $description = 'Nhắc GV / trợ giảng nộp báo cáo giảng dạy tháng (hạn Chủ nhật cuối tháng)';

    private const LABELS = [
        'due_minus_3' => 'còn 3 ngày',
        'due_minus_1' => 'còn 1 ngày',
        'due' => 'hôm nay là hạn',
    ];

    public function handle(): int
    {
        $today = Carbon::today();
        $kind = MonthlyReportDue::reminderKind($today);
        if ($kind === null) {
            $this->info('Hôm nay không phải ngày nhắc báo cáo tháng.');

            return self::SUCCESS;
        }

        $due = MonthlyReportDue::dueDate($today);
        $month = $today->format('Y-m');
        $count = 0;

        User::query()->where('is_active', true)->with('roles')->orderBy('id')->chunkById(200, function ($users) use ($today, $due, $kind, $month, &$count) {
            foreach ($users as $user) {
                if (StaffType::reportCadence($user) !== 'monthly') {
                    continue;
                }
                $submitted = StaffReport::where('user_id', $user->id)->where('type', 'monthly')
                    ->whereBetween('report_date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])
                    ->exists();
                if ($submitted) {
                    continue;
                }
                $exists = AdminNotification::where('user_id', $user->id)->where('type', 'monthly_report_due')
                    ->where('data->month', $month)->where('data->kind', $kind)->exists();
                if ($exists) {
                    continue;
                }

                AdminNotification::create([
                    'user_id' => $user->id,
                    'type' => 'monthly_report_due',
                    'title' => 'Nhắc nộp báo cáo giảng dạy tháng '.$today->format('m/Y'),
                    'message' => 'Hạn nộp: Chủ nhật '.$due->format('d/m').' ('.self::LABELS[$kind].'). Bạn chưa nộp báo cáo tháng này.',
                    'data' => ['month' => $month, 'kind' => $kind, 'due' => $due->toDateString(), 'link' => route('reports.my')],
                    'is_read' => false,
                ]);
                $count++;
            }
        });

        $this->info("Đã nhắc {$count} người nộp báo cáo giảng dạy tháng ({$kind}).");

        return self::SUCCESS;
    }
}

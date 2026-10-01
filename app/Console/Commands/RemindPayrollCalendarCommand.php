<?php

namespace App\Console\Commands;

use App\Models\AdminNotification;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nhắc theo lịch chốt lương (chủ dự án chốt): chốt KPI ngày cuối tháng; chốt công + chốt lỗi hết cuối tháng + 2 ngày;
 * trả lương ngày 10–15 tháng sau. Chạy hằng ngày, idempotent: mỗi (người, kỳ, mốc) chỉ nhận 1 thông báo.
 *   - ngày cuối tháng  → người chốt KPI (kpi.confirm)
 *   - +1 / +2 ngày     → người xử lý chấm công / quyết phạt / chốt lương (nhắc làm xong trước khi chốt)
 *   - ngày 10          → người duyệt / chi lương: khung trả lương 10–15
 */
class RemindPayrollCalendarCommand extends Command
{
    protected $signature = 'payroll:remind-calendar';

    protected $description = 'Nhắc lịch chốt KPI / công / lỗi và khung trả lương 10–15';

    public function handle(): int
    {
        $today = today();
        $created = 0;

        PayrollPeriod::query()->where('status', '!=', 'paid')->orderBy('id')->get()->each(function (PayrollPeriod $period) use ($today, &$created) {
            $end = Carbon::parse($period->end_date)->startOfDay();
            $label = 'tháng '.str_pad((string) $period->month, 2, '0', STR_PAD_LEFT).'/'.$period->year;
            $link = route('payroll.periods.show', $period->id);

            if ($today->isSameDay($end) && ! $period->isLocked()) {
                $pending = $period->records()->get()->filter(fn ($r) => $r->kpi_state[0] === 'pending')->count();
                $created += $this->notify($period, 'kpi_close', ['kpi.confirm'], "Hôm nay chốt KPI {$label}",
                    'Chốt đánh giá KPI tháng'.($pending ? " — còn {$pending} nhân sự chưa chốt KPI trên bảng lương" : '').'.',
                    route('kpi.monthly', ['month' => $period->month, 'year' => $period->year]));
            }

            $afterEnd = (int) $end->diffInDays($today, false);
            if (in_array($afterEnd, [1, 2], true) && ! $period->isLocked()) {
                $closeOn = $period->attendanceCloseAt()->format('d/m');
                $created += $this->notify($period, 'close_d'.$afterEnd,
                    ['attendance_staff.view', 'violation.confirm_fine', 'payroll.approve'],
                    "Sắp chốt công và chốt lỗi {$label} (hết ngày {$closeOn})",
                    'Hoàn tất chấm công / duyệt giờ dạy và quyết định các biên bản vi phạm trước khi chốt bảng lương.', $link);
            }

            if ($today->day === PayrollPeriod::PAY_DAY_FROM && $today->isSameMonth($period->payWindow()[0])) {
                $created += $this->notify($period, 'pay_window', ['payroll.approve', 'payroll.mark_paid'],
                    "Trả lương {$label}: ".PayrollPeriod::PAY_DAY_FROM.'–'.PayrollPeriod::PAY_DAY_TO.' tháng này',
                    $period->isLocked() ? 'Bảng lương đã chốt — chi trả từ hôm nay đến ngày '.PayrollPeriod::PAY_DAY_TO.'.'
                        : 'Bảng lương chưa chốt — hoàn tất chốt lương để kịp trả trong khung '.PayrollPeriod::PAY_DAY_FROM.'–'.PayrollPeriod::PAY_DAY_TO.'.', $link);
            }
        });

        $this->info("Đã gửi {$created} nhắc lịch chốt lương.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $permissions */
    private function notify(PayrollPeriod $period, string $stage, array $permissions, string $title, string $message, string $link): int
    {
        $userIds = collect($permissions)
            ->flatMap(fn (string $permission) => Rbac::scopeUsersWithPermission(User::query()->where('is_active', true), $permission)->pluck('id'))
            ->unique();
        $count = 0;
        foreach ($userIds as $userId) {
            $exists = AdminNotification::query()
                ->where('type', 'payroll_calendar')
                ->where('user_id', $userId)
                ->where('data->period_id', $period->id)
                ->where('data->stage', $stage)
                ->exists();
            if ($exists) {
                continue;
            }
            AdminNotification::create([
                'user_id' => $userId,
                'type' => 'payroll_calendar',
                'title' => $title,
                'message' => $message,
                'data' => ['period_id' => $period->id, 'stage' => $stage, 'link' => $link],
                'is_read' => false,
            ]);
            $count++;
        }

        return $count;
    }
}

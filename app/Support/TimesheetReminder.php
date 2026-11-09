<?php

namespace App\Support;

use App\Models\StaffReport;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Nhắc chấm công từ thứ Hai của tuần cuối tháng, giữ các tháng chưa nộp. */
final class TimesheetReminder
{
    public const FIRST_MONTH = '2026-11';

    public static function eligible(User $user): bool
    {
        return ! StaffType::isAssistantOnly($user)
            && array_intersect(['teacher_monthly', 'teacher_attendance'], StaffType::structuredReports($user)) !== [];
    }

    public static function startsOn(string $month): Carbon
    {
        return ReportPeriod::monthRange($month)[0]->endOfMonth()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** @return list<string> */
    public static function pendingMonths(User $user, CarbonInterface $today): array
    {
        if (! self::eligible($user)) {
            return [];
        }
        $floor = max(self::FIRST_MONTH, $user->created_at?->format('Y-m') ?? self::FIRST_MONTH);
        $current = $today->format('Y-m');
        $submitted = StaffReport::where('user_id', $user->id)->where('kind', 'teacher_attendance')
            ->where('status', 'submitted')->whereBetween('period_key', [$floor, $current])->pluck('period_key')->all();
        $pending = [];
        for ($month = ReportPeriod::monthRange($floor)[0]; $month->format('Y-m') <= $current; $month->addMonth()) {
            $key = $month->format('Y-m');
            if ($today->gte(self::startsOn($key)) && ! in_array($key, $submitted, true)) {
                $pending[] = $key;
            }
        }

        return $pending;
    }

    /** @return list<array{month: string, label: string, href: string}> */
    public static function links(User $user, CarbonInterface $today): array
    {
        $route = in_array('teacher_attendance', StaffType::structuredReports($user), true)
            ? 'reports.periodic.timesheet-monthly' : 'reports.periodic.teacher-monthly';

        return array_map(fn (string $month) => [
            'month' => $month,
            'label' => ReportPeriod::monthLabel($month),
            'href' => route($route, ['month' => $month]),
        ], self::pendingMonths($user, $today));
    }
}

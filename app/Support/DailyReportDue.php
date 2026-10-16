<?php

namespace App\Support;

use App\Models\StaffAttendanceRequest;
use App\Models\StaffReport;
use App\Models\User;
use App\Services\SessionScheduleService;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ngày phải nộp báo cáo ngày (Học vụ / Học thuật — StaffType::structuredReports có daily_ops): thứ Hai → thứ Bảy,
 * trừ ngày nghỉ lễ (toàn hệ thống / chi nhánh của người viết) và ngày nghỉ phép đã duyệt. Hạn nộp 09:00 sáng hôm sau.
 * Chủ dự án 16/10/2026: ngày phải nộp mà không có báo cáo tính là nộp trễ (KPI 4.1).
 */
final class DailyReportDue
{
    public const DEADLINE = '09:00';

    /** Trước giờ này, trang báo cáo ngày mở sẵn hôm qua nếu hôm qua chưa nộp. */
    public const OPEN_YESTERDAY_BEFORE = '10:00';

    public static function appliesTo(User $user): bool
    {
        return in_array('daily_ops', StaffType::structuredReports($user), true);
    }

    /**
     * Người phải nộp báo cáo ngày (Học vụ / Học thuật đang làm), giới hạn trong $userIds nếu có (phạm vi người xem).
     *
     * @param  list<int>|null  $userIds
     * @return Collection<int, User>
     */
    public static function writers(?array $userIds = null): Collection
    {
        return User::role([Roles::ACADEMIC_STAFF, Roles::ACADEMIC_LEAD])->where('is_active', true)
            ->when($userIds !== null, fn ($q) => $q->whereIn('id', $userIds))
            ->with(['roles', 'branch:id,name'])->orderBy('name')->get()
            ->filter(fn (User $u) => self::appliesTo($u))->values();
    }

    /** Hạn nộp báo cáo của ngày $date: 09:00 sáng hôm sau. */
    public static function deadline(CarbonInterface $date): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', self::DEADLINE));

        return Carbon::parse($date->toDateString())->addDay()->setTime($h, $m);
    }

    /**
     * Ngày phải nộp trong khoảng [$from, $to], chỉ tính ngày đã qua hạn hoặc đã tới (không tính hôm nay trở đi)
     * và không tính những ngày trước khi tài khoản được tạo (người mới vào giữa tháng).
     *
     * @return Collection<int, Carbon>
     */
    public static function requiredDays(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $to = Carbon::parse(min($to->toDateString(), today()->subDay()->toDateString()));
        $from = Carbon::parse($from->toDateString());
        if ($user->created_at !== null && $user->created_at->toDateString() > $from->toDateString()) {
            $from = Carbon::parse($user->created_at->toDateString());
        }
        if ($from->gt($to)) {
            return collect();
        }
        $holidays = app(SessionScheduleService::class)->holidayDates($user->branch_id ? (int) $user->branch_id : null, $from, $to);
        $leave = self::leaveDates($user, $from, $to);

        return collect(CarbonPeriod::create($from, $to))
            ->map(fn ($d) => Carbon::parse($d->toDateString()))
            ->reject(fn (Carbon $d) => $d->isSunday() || isset($holidays[$d->toDateString()]) || isset($leave[$d->toDateString()]))
            ->values();
    }

    /**
     * Ngày phải nộp mà chưa có báo cáo (bản có cấu trúc đã nộp, hoặc báo cáo ngày chữ tự do cũ).
     *
     * @return Collection<int, Carbon>
     */
    public static function missingDays(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $required = self::requiredDays($user, $from, $to);
        if ($required->isEmpty()) {
            return $required;
        }
        $submitted = self::submittedDates($user, $required->first(), $required->last());

        return $required->reject(fn (Carbon $d) => isset($submitted[$d->toDateString()]))->values();
    }

    /** @return array<string, true> ngày (Y-m-d) đã có báo cáo ngày */
    public static function submittedDates(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        return StaffReport::where('user_id', $user->id)->where('type', 'daily')->where('status', '!=', 'draft')
            ->whereDate('report_date', '>=', $from->toDateString())->whereDate('report_date', '<=', $to->toDateString())
            ->pluck('report_date')->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])->all();
    }

    /** Báo cáo nộp lần đầu sau hạn 09:00 hôm sau. */
    public static function isLate(StaffReport $report): bool
    {
        return $report->type === 'daily' && $report->created_at !== null && $report->created_at->gt(self::deadline($report->report_date));
    }

    /** @return array<string, true> */
    private static function leaveDates(User $user, Carbon $from, Carbon $to): array
    {
        $dates = [];
        StaffAttendanceRequest::where('user_id', $user->id)
            ->where('type', StaffAttendanceRequest::TYPE_LEAVE)->where('status', StaffAttendanceRequest::STATUS_APPROVED)
            ->whereDate('date_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereDate('date_to', '>=', $from->toDateString())->orWhere(fn ($n) => $n->whereNull('date_to')->whereDate('date_from', '>=', $from->toDateString())))
            ->get(['date_from', 'date_to'])
            ->each(function (StaffAttendanceRequest $r) use (&$dates, $from, $to) {
                $start = $r->date_from->max($from);
                $end = ($r->date_to ?? $r->date_from)->min($to);
                foreach (CarbonPeriod::create($start, $end) as $d) {
                    $dates[$d->toDateString()] = true;
                }
            });

        return $dates;
    }
}

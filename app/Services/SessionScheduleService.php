<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Holiday;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * Sinh buổi học theo ca lặp tuần, loại ngày nghỉ lễ và phát hiện xung đột
 * phòng/nhân sự với buổi học của lớp khác. Dùng chung cho tạo lớp, xếp TKB
 * và sửa lớp để các luồng không lệch quy tắc với nhau.
 *
 * Buổi học được biểu diễn dạng mảng: ['date' => 'Y-m-d', 'start' => 'H:i', 'end' => 'H:i', 'room' => ?string, ...].
 */
class SessionScheduleService
{
    public const DAY_MAP = ['Thứ 2' => 1, 'Thứ 3' => 2, 'Thứ 4' => 3, 'Thứ 5' => 4, 'Thứ 6' => 5, 'Thứ 7' => 6, 'Chủ nhật' => 7];

    /**
     * Sinh buổi học cho các ca (['day','start','end','name']) trong khoảng ngày, bỏ ngày nghỉ lễ
     * áp dụng cho chi nhánh (hoặc toàn hệ thống).
     */
    public function generate(array $slots, CarbonInterface $from, CarbonInterface $to, ?int $branchId): array
    {
        if ($from->gt($to) || empty($slots)) {
            return [];
        }

        $holidays = $this->holidayDates($branchId, $from, $to);
        $sessions = [];
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $date) {
            $day = $date->toDateString();
            if (isset($holidays[$day])) {
                continue;
            }
            foreach ($slots as $slot) {
                if ($date->isoWeekday() === self::DAY_MAP[$slot['day']]) {
                    $sessions[] = ['date' => $day] + $slot;
                }
            }
        }

        return $sessions;
    }

    /**
     * Loại các buổi rơi vào ngày nghỉ lễ (dùng cho TKB do client render sẵn).
     */
    public function withoutHolidays(array $sessions, ?int $branchId): array
    {
        if (empty($sessions)) {
            return [];
        }

        $dates = array_column($sessions, 'date');
        $holidays = $this->holidayDates($branchId, Carbon::parse(min($dates)), Carbon::parse(max($dates)));

        return array_values(array_filter($sessions, fn (array $session) => ! isset($holidays[$session['date']])));
    }

    /**
     * Tập ngày nghỉ (key 'Y-m-d') trong khoảng, gồm nghỉ toàn hệ thống và nghỉ riêng của chi nhánh.
     *
     * @return array<string, true>
     */
    public function holidayDates(?int $branchId, CarbonInterface $from, CarbonInterface $to): array
    {
        $holidays = Holiday::query()
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->where(function ($query) use ($branchId) {
                $query->where('is_system_wide', true);
                if ($branchId) {
                    $query->orWhereHas('branches', fn ($branch) => $branch->where('branches.id', $branchId));
                }
            })
            ->get(['start_date', 'end_date']);

        $dates = [];
        foreach ($holidays as $holiday) {
            $start = $holiday->start_date->max($from->copy()->startOfDay());
            $end = $holiday->end_date->min($to->copy()->startOfDay());
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $dates[$date->toDateString()] = true;
            }
        }

        return $dates;
    }

    /**
     * Tìm buổi học (chưa hủy) của lớp khác trùng giờ và trùng phòng cùng chi nhánh
     * hoặc trùng giáo viên/trợ giảng. Một truy vấn cho cả đợt thay vì mỗi buổi một truy vấn.
     *
     * @return array{0: array, 1: ClassSession}|null [buổi bị trùng, buổi đang chiếm]
     */
    public function findConflict(array $sessions, ?int $branchId, array $resourceIds, ?string $defaultRoom = null, ?int $excludeClassId = null): ?array
    {
        $resourceIds = array_values(array_unique(array_map('intval', array_filter($resourceIds))));
        $rooms = collect($sessions)->map(fn ($session) => ($session['room'] ?? null) ?: $defaultRoom)->filter()->unique()->values()->all();
        if (empty($sessions) || (empty($resourceIds) && empty($rooms))) {
            return null;
        }

        $dates = array_column($sessions, 'date');
        $candidates = ClassSession::query()
            ->with('classModel:id,name,code')
            ->where('status', '!=', 'cancelled')
            ->when($excludeClassId, fn ($query) => $query->where('class_id', '!=', $excludeClassId))
            ->whereDate('date', '>=', min($dates))
            ->whereDate('date', '<=', max($dates))
            ->where(function ($query) use ($branchId, $rooms, $resourceIds) {
                if ($rooms) {
                    $query->orWhere(fn ($room) => $room->where('branch_id', $branchId)->whereIn('room', $rooms));
                }
                if ($resourceIds) {
                    $query->orWhereIn('teacher_id', $resourceIds)->orWhereIn('assistant_id', $resourceIds);
                }
            })
            ->get()
            ->groupBy(fn (ClassSession $session) => $session->date->toDateString());

        foreach ($sessions as $session) {
            $room = ($session['room'] ?? null) ?: $defaultRoom;
            foreach ($candidates->get($session['date'], []) as $existing) {
                $overlaps = strcmp(self::hm($existing->getRawOriginal('start_time')), $session['end']) < 0
                    && strcmp(self::hm($existing->getRawOriginal('end_time')), $session['start']) > 0;
                if (! $overlaps) {
                    continue;
                }
                $sameRoom = $room && (int) $existing->branch_id === (int) $branchId && $existing->room === $room;
                $sameStaff = in_array((int) $existing->teacher_id, $resourceIds, true)
                    || in_array((int) $existing->assistant_id, $resourceIds, true);
                if ($sameRoom || $sameStaff) {
                    return [$session, $existing];
                }
            }
        }

        return null;
    }

    private static function hm(?string $time): string
    {
        return substr((string) $time, 0, 5);
    }
}

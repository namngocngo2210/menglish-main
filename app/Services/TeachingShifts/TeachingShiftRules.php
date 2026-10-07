<?php

namespace App\Services\TeachingShifts;

use App\Models\ClassSession;
use App\Models\TeachingShift;
use App\Services\SessionScheduleService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Quy tắc khung giờ ca dạy (file "Khung giờ chấm công ME"):
 *  - Thứ 2 – Thứ 6 chỉ có các ca trong danh mục (Ca 1 / Ca 2), giờ chuẩn hoặc giờ lệch của khung.
 *  - Thứ 7 / Chủ nhật không chia cứng ca: lấy giờ thực tế của lớp trên TKB (khung trong danh mục chỉ là gợi ý).
 *  - Mỗi ca dạy 90 phút.
 *  - Chấm công GV đối chiếu theo giờ buổi học được phân công (ClassSession), không theo giờ chung cố định.
 * Loại ngày chưa có khung nào đang dùng thì không ép theo khung (chỉ kiểm 90 phút).
 */
class TeachingShiftRules
{
    /** Tên ca cuối tuần khi giờ của lớp không trùng khung nào trong danh mục. */
    public const WEEKEND_CUSTOM_NAME = 'Ca theo TKB';

    /** @var array<string, \Illuminate\Database\Eloquent\Collection<int, TeachingShift>> */
    private array $cache = [];

    /** @return \Illuminate\Database\Eloquent\Collection<int, TeachingShift> */
    public function framesFor(string $dayType)
    {
        return $this->cache[$dayType] ??= TeachingShift::activeFor($dayType);
    }

    /** Khung (tên ca + biến thể) trùng đúng giờ bắt đầu – kết thúc, null nếu không có. */
    public function match(string $dayType, string $start, string $end): ?array
    {
        foreach ($this->framesFor($dayType) as $shift) {
            foreach ($shift->variants() as $variant) {
                if ($variant['start'] === $start && $variant['end'] === $end) {
                    return ['shift' => $shift, 'name' => $shift->name, 'variant' => $variant['variant']];
                }
            }
        }

        return null;
    }

    /** Lỗi của một ca học lặp tuần (null = hợp lệ). $day là "Thứ 2" … "Chủ nhật", giờ dạng "HH:MM". */
    public function violation(string $day, string $start, string $end): ?string
    {
        $minutes = $this->minutes($start, $end);
        if ($minutes !== TeachingShift::DURATION_MINUTES) {
            return "{$day}: mỗi ca dạy ".TeachingShift::DURATION_MINUTES." phút — ca bắt đầu {$start} phải kết thúc lúc {$this->addMinutes($start, TeachingShift::DURATION_MINUTES)}.";
        }

        $dayType = TeachingShift::dayTypeOf($day);
        if ($dayType === TeachingShift::WEEKDAY && $this->framesFor($dayType)->isNotEmpty() && ! $this->match($dayType, $start, $end)) {
            return "{$day} chỉ có {$this->weekdayFramesText()}. Chọn lại ca theo khung giờ.";
        }

        return null;
    }

    /**
     * Kiểm các ca (['day','start','end', ...]) và đặt tên ca theo khung: Thứ 2–6 lấy tên khung (Ca 1 / Ca 2),
     * cuối tuần lấy tên khung trùng giờ, không trùng thì "Ca theo TKB".
     *
     * @throws ValidationException
     */
    public function apply(array $slots, string $errorKeyPrefix = 'slot'): array
    {
        foreach ($slots as $i => $slot) {
            if ($error = $this->violation($slot['day'], $slot['start'], $slot['end'])) {
                throw ValidationException::withMessages([$errorKeyPrefix.($i + 1).'_start' => $error]);
            }
            $slots[$i]['name'] = $this->nameFor($slot['day'], $slot['start'], $slot['end']);
        }

        return $slots;
    }

    public function nameFor(string $day, string $start, string $end): string
    {
        $dayType = TeachingShift::dayTypeOf($day);

        return $this->match($dayType, $start, $end)['name']
            ?? ($dayType === TeachingShift::WEEKDAY ? 'Ca '.$start : self::WEEKEND_CUSTOM_NAME);
    }

    /** "Ca 1 (18:00–19:30 hoặc 18:10–19:40) và Ca 2 (…)". */
    public function weekdayFramesText(): string
    {
        $parts = $this->framesFor(TeachingShift::WEEKDAY)
            ->map(fn (TeachingShift $s) => $s->name.' ('.$s->standardRange().($s->altRange() ? ' hoặc '.$s->altRange() : '').')')
            ->values()->all();

        return match (count($parts)) {
            0 => 'ca theo danh mục',
            1 => $parts[0],
            default => implode(', ', array_slice($parts, 0, -1)).' và '.end($parts),
        };
    }

    /**
     * Lựa chọn ca cho form TKB theo loại ngày.
     *
     * @return array<string, list<array{value: string, label: string, name: string, start: string, end: string, variant: string}>>
     */
    public function options(): array
    {
        $options = [];
        foreach (array_keys(TeachingShift::DAY_TYPES) as $dayType) {
            $options[$dayType] = [];
            foreach ($this->framesFor($dayType) as $shift) {
                foreach ($shift->variants() as $variant) {
                    $value = $variant['start'].'-'.$variant['end'];
                    if (collect($options[$dayType])->contains('value', $value)) {
                        continue;
                    }
                    $options[$dayType][] = [
                        'value' => $value,
                        'label' => $shift->name.' · '.$variant['start'].'–'.$variant['end'].($variant['variant'] === 'alt' ? ' (giờ lệch)' : ''),
                        'name' => $shift->name,
                        'start' => $variant['start'],
                        'end' => $variant['end'],
                        'variant' => $variant['variant'],
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * Lớp có buổi chính khóa sắp tới (chưa hủy) lệch khung giờ — để Học vụ xếp lại TKB.
     *
     * @param  Collection<int, int>|null  $classIds  giới hạn theo phạm vi người xem (null = mọi lớp)
     * @return Collection<int, array{class_id: int, class_name: ?string, class_code: ?string, branch: ?string, slots: list<string>, sessions: int}>
     */
    public function offScheduleClasses(?Collection $classIds = null, int $days = 60): Collection
    {
        return ClassSession::query()
            ->with('classModel:id,name,code,branch_id', 'classModel.branch:id,name')
            ->where('type', ClassSession::TYPE_REGULAR)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', today()->toDateString())
            ->whereDate('date', '<=', today()->addDays($days)->toDateString())
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->get(['id', 'class_id', 'date', 'start_time', 'end_time'])
            ->filter(function (ClassSession $s) {
                $day = array_search($s->date->isoWeekday(), SessionScheduleService::DAY_MAP, true);

                return $day && $s->start_time && $s->end_time
                    && $this->violation($day, $s->start_time->format('H:i'), $s->end_time->format('H:i')) !== null;
            })
            ->groupBy('class_id')
            ->map(function (Collection $sessions) {
                $class = $sessions->first()->classModel;

                return [
                    'class_id' => (int) $sessions->first()->class_id,
                    'class_name' => $class?->name,
                    'class_code' => $class?->code,
                    'branch' => $class?->branch?->name,
                    'slots' => $sessions->map(fn (ClassSession $s) => array_search($s->date->isoWeekday(), SessionScheduleService::DAY_MAP, true)
                        .' '.$s->start_time->format('H:i').'–'.$s->end_time->format('H:i'))->unique()->values()->all(),
                    'sessions' => $sessions->count(),
                ];
            })
            ->sortBy('class_name')
            ->values();
    }

    private function minutes(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }

    private function addMinutes(string $time, int $minutes): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $total = min(23 * 60 + 59, $h * 60 + $m + $minutes);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}

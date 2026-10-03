<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Kỳ báo cáo dạng khóa chuỗi: tuần ISO "2026-W35", tháng "2026-10", quý "2026-Q3".
 * Dùng cho báo cáo có cấu trúc (staff_reports.period_key), checklist / dự giờ theo tháng.
 */
final class ReportPeriod
{
    public const WEEK_PATTERN = '/^\d{4}-W\d{2}$/';

    public const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public const QUARTER_PATTERN = '/^\d{4}-Q[1-4]$/';

    public static function currentWeek(): string
    {
        return self::weekKey(now());
    }

    public static function currentMonth(): string
    {
        return now()->format('Y-m');
    }

    public static function currentQuarter(): string
    {
        return now()->year.'-Q'.now()->quarter;
    }

    public static function weekKey(Carbon $date): string
    {
        return $date->isoFormat('GGGG-[W]WW');
    }

    /** @return array{0: Carbon, 1: Carbon} Thứ Hai → Chủ nhật của tuần ISO. */
    public static function weekRange(string $key): array
    {
        [$year, $week] = explode('-W', $key);
        $start = Carbon::now()->setISODate((int) $year, (int) $week)->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [$start, $start->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function monthRange(string $key): array
    {
        $start = Carbon::createFromFormat('!Y-m', $key)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function quarterRange(string $key): array
    {
        [$year, $quarter] = explode('-Q', $key);
        $start = Carbon::create((int) $year, ((int) $quarter - 1) * 3 + 1, 1)->startOfDay();

        return [$start, $start->copy()->addMonths(2)->endOfMonth()];
    }

    /** @return list<string> 3 tháng của quý, vd. 2026-Q3 → 2026-07, 2026-08, 2026-09. */
    public static function quarterMonths(string $key): array
    {
        $start = self::quarterRange($key)[0];

        return array_map(fn (int $i) => $start->copy()->addMonths($i)->format('Y-m'), [0, 1, 2]);
    }

    public static function weekLabel(string $key): string
    {
        [$start, $end] = self::weekRange($key);

        return $key.' ('.$start->format('d/m').' – '.$end->format('d/m').')';
    }

    public static function monthLabel(string $key): string
    {
        return 'Tháng '.self::monthRange($key)[0]->format('m/Y');
    }

    public static function quarterLabel(string $key): string
    {
        [$start, $end] = self::quarterRange($key);

        return 'Quý '.substr($key, -1).'/'.$start->year.' (tháng '.$start->month.' – tháng '.$end->month.')';
    }

    /** @return list<array{value: string, label: string}> Tuần hiện tại và các tuần trước. */
    public static function weekOptions(int $back = 8): array
    {
        return array_map(function (int $i) {
            $key = self::weekKey(now()->subWeeks($i));

            return ['value' => $key, 'label' => self::weekLabel($key)];
        }, range(0, $back));
    }

    /** @return list<array{value: string, label: string}> Từ tháng sau về các tháng trước. */
    public static function monthOptions(int $back = 6, int $forward = 0): array
    {
        return array_map(function (int $i) {
            $key = now()->startOfMonth()->addMonths($i)->format('Y-m');

            return ['value' => $key, 'label' => self::monthLabel($key)];
        }, range($forward, -$back));
    }

    /** @return list<array{value: string, label: string}> Quý hiện tại và các quý trước. */
    public static function quarterOptions(int $back = 4): array
    {
        return array_map(function (int $i) {
            $date = now()->startOfQuarter()->subQuarters($i);
            $key = $date->year.'-Q'.$date->quarter;

            return ['value' => $key, 'label' => self::quarterLabel($key)];
        }, range(0, $back));
    }

    /** Khóa hợp lệ theo mẫu, ngược lại trả giá trị mặc định. */
    public static function pick(?string $value, string $pattern, string $default): string
    {
        return is_string($value) && preg_match($pattern, $value) ? $value : $default;
    }
}

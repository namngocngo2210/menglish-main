<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Hạn nộp báo cáo giảng dạy tháng của GV / trợ giảng: CHỦ NHẬT CUỐI CÙNG của tháng.
 * Chỉ nhắc, không phạt (xem reports:remind-monthly).
 */
final class MonthlyReportDue
{
    /** Chủ nhật cuối cùng của tháng chứa $month. */
    public static function dueDate(CarbonInterface $month): Carbon
    {
        $end = Carbon::instance($month)->copy()->endOfMonth()->startOfDay();

        return $end->dayOfWeek === Carbon::SUNDAY ? $end : $end->previous(Carbon::SUNDAY);
    }

    /**
     * Loại nhắc của ngày $today so với hạn của tháng đó: 'due_minus_3' (còn 3 ngày), 'due_minus_1' (còn 1 ngày),
     * 'due' (đúng hạn); ngày khác → null.
     */
    public static function reminderKind(CarbonInterface $today): ?string
    {
        $due = self::dueDate($today);
        $days = (int) Carbon::instance($today)->startOfDay()->diffInDays($due, false);

        return match ($days) {
            3 => 'due_minus_3',
            1 => 'due_minus_1',
            0 => 'due',
            default => null,
        };
    }
}

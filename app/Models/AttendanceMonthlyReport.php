<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class AttendanceMonthlyReport extends Model
{
    protected $fillable = ['user_id', 'month', 'submitted_at', 'note'];

    protected $casts = ['submitted_at' => 'datetime'];

    /** @return list<string> Oldest first, limited to the previous and current months. */
    public static function unsubmittedMonths(int $userId, CarbonInterface $today): array
    {
        $months = [$today->copy()->startOfMonth()->subMonth()->format('Y-m'), $today->format('Y-m')];
        $submitted = self::query()->where('user_id', $userId)->whereIn('month', $months)->pluck('month')->all();

        return array_values(array_diff($months, $submitted));
    }

    public static function pendingMonth(int $userId, CarbonInterface $today): ?string
    {
        // BA 09/11: current month is reminded from (last day of month - 7 days); an earlier month stays pending.
        foreach (self::unsubmittedMonths($userId, $today) as $month) {
            if ($month !== $today->format('Y-m') || $today->gte($today->copy()->endOfMonth()->startOfDay()->subDays(7))) {
                return $month;
            }
        }

        return null;
    }
}

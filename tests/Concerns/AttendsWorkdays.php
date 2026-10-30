<?php

namespace Tests\Concerns;

use App\Models\StaffAttendance;
use App\Models\User;
use Carbon\Carbon;

/**
 * Chấm công hằng ngày cho test bảng lương: lương cơ bản Full-time trả theo ngày công (TK-0012), nên nhân sự
 * Full-time trong test cần có ngày công để nhận đủ lương cơ bản.
 */
trait AttendsWorkdays
{
    /** Chấm đủ công (08:00–17:00, không muộn) mọi ngày làm việc của tháng, trừ các ngày trong $skip (Y-m-d). */
    protected function attendFullMonth(User $user, int $month, int $year = 2026, array $skip = []): int
    {
        $count = 0;
        $start = Carbon::create($year, $month, 1);
        for ($day = $start->copy(); $day->month === $month; $day->addDay()) {
            if ($user->isPayrollDayOff($day) || in_array($day->toDateString(), $skip, true)) {
                continue;
            }
            $this->attendDay($user, $day->toDateString());
            $count++;
        }

        return $count;
    }

    protected function attendDay(User $user, string $date, ?string $in = '08:00', ?string $out = '17:00', int $late = 0, bool $excused = false): StaffAttendance
    {
        // whereDate: SQLite lưu cột date kèm giờ (Y-m-d 00:00:00), so khớp chuỗi Y-m-d sẽ trượt.
        $row = StaffAttendance::where('user_id', $user->id)->whereDate('work_date', $date)->first()
            ?? new StaffAttendance(['user_id' => $user->id, 'work_date' => $date]);
        $row->fill([
            'branch_id' => $user->branch_id,
            'check_in_at' => $in ? $date.' '.$in.':00' : null,
            'check_out_at' => $out ? $date.' '.$out.':00' : null,
            'late_minutes' => $late,
            'late_excused' => $excused,
        ])->save();

        return $row;
    }
}

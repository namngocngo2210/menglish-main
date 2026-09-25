<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Holiday;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Áp ngày nghỉ lễ được thêm/sửa/xóa SAU khi lịch học đã sinh:
 *  - Buổi sắp tới (từ hôm nay) rơi vào ngày nghỉ, của chi nhánh bị ảnh hưởng, chưa có dữ liệu
 *    thực tế (điểm danh/chấm công/buổi phụ đạo) → chuyển "cancelled", gắn holiday_id.
 *  - Mỗi buổi bị hủy được xếp một buổi học bù (type = makeup) vào ca học kế tiếp của lớp SAU buổi
 *    cuối cùng hiện có, bỏ qua ngày nghỉ và không trùng phòng/nhân sự.
 *  - Buổi đã có dữ liệu thực tế không bị đụng tới, chỉ được đếm là "cần xử lý tay".
 *  - Khi ngày nghỉ bị thu hẹp/xóa: buổi đã hủy không còn nằm trong ngày nghỉ được khôi phục và buổi
 *    học bù tương ứng bị xóa (nếu buổi bù chưa có dữ liệu thực tế).
 */
class HolidayRescheduleService
{
    /** Số ngày tối đa tìm ca học bù sau buổi cuối của lớp. */
    private const SEARCH_DAYS = 180;

    public function __construct(private readonly SessionScheduleService $schedule) {}

    /**
     * @return array{cancelled: int, rescheduled: int, unscheduled: int, flagged: int, restored: int}
     */
    public function apply(Holiday $holiday): array
    {
        return DB::transaction(function () use ($holiday) {
            $holiday->loadMissing('branches');
            $restored = $this->restoreUncovered($holiday);

            $today = now()->startOfDay();
            $from = $holiday->start_date->copy()->max($today);
            $result = ['cancelled' => 0, 'rescheduled' => 0, 'unscheduled' => 0, 'flagged' => 0, 'restored' => $restored];
            if ($holiday->trashed() || $from->gt($holiday->end_date)) {
                return $result;
            }

            $affected = $this->affectedQuery($holiday, $from)->get();
            $result['flagged'] = $affected->filter(fn (ClassSession $s) => $s->attendances_count || $s->timesheets_count || $s->support_session_count)->count();
            $toCancel = $affected->reject(fn (ClassSession $s) => $s->attendances_count || $s->timesheets_count || $s->support_session_count)
                ->sortBy(fn (ClassSession $s) => $s->class_id.'|'.$s->date->toDateString().'|'.$s->getRawOriginal('start_time'));

            foreach ($toCancel as $session) {
                $session->update([
                    'status' => 'cancelled',
                    'holiday_id' => $holiday->id,
                    'notes' => trim(($session->notes ? $session->notes."\n" : '')."Hủy do nghỉ lễ: {$holiday->name}"),
                ]);
                $result['cancelled']++;

                if ($this->scheduleMakeup($session, $holiday)) {
                    $result['rescheduled']++;
                } else {
                    $result['unscheduled']++;
                }
            }

            return $result;
        });
    }

    /**
     * Ngày nghỉ bị xóa: khôi phục mọi buổi đã hủy vì nó (nếu buổi bù chưa diễn ra).
     */
    public function release(Holiday $holiday): int
    {
        return DB::transaction(fn () => $this->restoreUncovered($holiday, releaseAll: true));
    }

    /**
     * Buổi học của lớp khác/cùng lớp trong khoảng ngày nghỉ thuộc chi nhánh bị ảnh hưởng.
     */
    private function affectedQuery(Holiday $holiday, Carbon $from): Builder
    {
        return ClassSession::query()
            ->withCount(['attendances', 'timesheets', 'supportSession'])
            ->where('status', 'scheduled')
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])
            ->whereNull('holiday_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $holiday->end_date->toDateString())
            ->when(! $holiday->is_system_wide, fn (Builder $q) => $q->whereIn('branch_id', $holiday->branches->modelKeys()));
    }

    private function covers(Holiday $holiday, ClassSession $session): bool
    {
        if ($holiday->trashed()) {
            return false;
        }
        $date = $session->date->toDateString();
        if ($date < $holiday->start_date->toDateString() || $date > $holiday->end_date->toDateString()) {
            return false;
        }

        return $holiday->is_system_wide || in_array((int) $session->branch_id, array_map('intval', $holiday->branches->modelKeys()), true);
    }

    private function restoreUncovered(Holiday $holiday, bool $releaseAll = false): int
    {
        $restored = 0;
        $cancelled = ClassSession::with('makeupSession')
            ->where('holiday_id', $holiday->id)
            ->where('status', 'cancelled')
            ->get();

        foreach ($cancelled as $session) {
            if (! $releaseAll && $this->covers($holiday, $session)) {
                continue;
            }
            $makeup = $session->makeupSession;
            if ($makeup) {
                $untouched = $makeup->status === 'scheduled'
                    && ! $makeup->attendances()->exists()
                    && ! $makeup->timesheets()->exists()
                    && ! $makeup->supportSession()->exists();
                if (! $untouched) {
                    // Buổi bù đã diễn ra: giữ nguyên buổi gốc ở trạng thái hủy để không dạy trùng.
                    continue;
                }
                $makeup->delete();
            }
            $session->update([
                'status' => 'scheduled',
                'holiday_id' => null,
                'notes' => trim(preg_replace('/\n?Hủy do nghỉ lễ: .*$/u', '', (string) $session->notes)) ?: null,
            ]);
            $restored++;
        }

        return $restored;
    }

    /**
     * Xếp buổi học bù cho buổi bị hủy vào ca kế tiếp của lớp, sau buổi cuối cùng hiện có.
     */
    private function scheduleMakeup(ClassSession $cancelled, Holiday $holiday): ?ClassSession
    {
        $class = ClassModel::with('scheduleConfig')->find($cancelled->class_id);
        if (! $class || in_array($class->status, ['cancelled', 'completed'], true)) {
            return null;
        }

        $slots = $this->classSlots($class, $cancelled);
        $lastDate = ClassSession::where('class_id', $class->id)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->where('status', '!=', 'cancelled')
            ->max('date');
        $from = collect([
            $lastDate ? Carbon::parse($lastDate) : null,
            $holiday->end_date->copy(),
            now()->startOfDay(),
        ])->filter()->max()->copy()->addDay()->startOfDay();
        $to = $from->copy()->addDays(self::SEARCH_DAYS);

        $holidays = $this->schedule->holidayDates($cancelled->branch_id ?? $class->branch_id, $from, $to);
        $staff = [$cancelled->teacher_id, $cancelled->foreign_teacher_id, $cancelled->assistant_id];

        foreach (CarbonPeriod::create($from, $to) as $date) {
            $day = $date->toDateString();
            if (isset($holidays[$day])) {
                continue;
            }
            foreach ($slots as $slot) {
                if ($date->isoWeekday() !== $slot['weekday']) {
                    continue;
                }
                $candidate = ['date' => $day, 'start' => $slot['start'], 'end' => $slot['end'], 'room' => $cancelled->room];
                $ownOverlap = ClassSession::where('class_id', $class->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('date', $day)
                    ->where('start_time', '<', $slot['end'].':00')
                    ->where('end_time', '>', $slot['start'].':00')
                    ->exists();
                if ($ownOverlap || $this->schedule->findConflict([$candidate], $cancelled->branch_id, $staff, $cancelled->room, $class->id)) {
                    continue;
                }

                $makeup = ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $cancelled->branch_id,
                    'date' => $day,
                    'shift_name' => $slot['name'] ?? $cancelled->shift_name,
                    'type' => ClassSession::TYPE_MAKEUP,
                    'start_time' => $slot['start'],
                    'end_time' => $slot['end'],
                    'room' => $cancelled->room,
                    'teacher_id' => $cancelled->teacher_id,
                    'foreign_teacher_id' => $cancelled->foreign_teacher_id,
                    'assistant_id' => $cancelled->assistant_id,
                    'status' => 'scheduled',
                    'rescheduled_from_id' => $cancelled->id,
                    'notes' => 'Học bù cho buổi '.$cancelled->date->format('d/m/Y')." (nghỉ lễ: {$holiday->name})",
                ]);

                if (! $class->end_date || $class->end_date->lt($date)) {
                    $class->update(['end_date' => $day]);
                }

                return $makeup;
            }
        }

        return null;
    }

    /**
     * Ca học lặp tuần của lớp: ưu tiên cấu hình TKB, nếu không có thì suy ra từ các buổi đã sinh.
     *
     * @return list<array{weekday: int, start: string, end: string, name: ?string}>
     */
    private function classSlots(ClassModel $class, ClassSession $fallback): array
    {
        $config = $class->scheduleConfig;
        $slots = [];
        if ($config) {
            foreach ([1, 2] as $n) {
                $day = $config->{"slot{$n}_day"};
                if ($day && isset(SessionScheduleService::DAY_MAP[$day])) {
                    $slots[] = [
                        'weekday' => SessionScheduleService::DAY_MAP[$day],
                        'start' => substr((string) $config->{"slot{$n}_start"}, 0, 5),
                        'end' => substr((string) $config->{"slot{$n}_end"}, 0, 5),
                        'name' => "Slot {$n}",
                    ];
                }
            }
        }

        if (empty($slots)) {
            /** @var Collection<int, ClassSession> $sessions */
            $sessions = ClassSession::where('class_id', $class->id)
                ->where('type', ClassSession::TYPE_REGULAR)
                ->get();
            $slots = $sessions->push($fallback)
                ->map(fn (ClassSession $s) => [
                    'weekday' => $s->date->isoWeekday(),
                    'start' => substr((string) $s->getRawOriginal('start_time'), 0, 5),
                    'end' => substr((string) $s->getRawOriginal('end_time'), 0, 5),
                    'name' => $s->shift_name,
                ])
                ->unique(fn (array $slot) => $slot['weekday'].'|'.$slot['start'])
                ->sortBy(fn (array $slot) => $slot['weekday'].'|'.$slot['start'])
                ->values()->all();
        }

        return $slots;
    }
}

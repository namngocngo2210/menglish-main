<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Nghiệp vụ phòng học dùng chung cho màn Phòng học, Tạo / Sửa lớp và Lịch & TKB lớp:
 * lớp nào đang dùng phòng (theo ca học tuần), tác động khi xóa / đổi tên phòng, chuyển phòng giữa các lớp,
 * tra cứu phòng trống theo buổi học thật.
 *
 * Lớp "đang học" (ClassModel::isStudying) giữ chặt phòng: không xóa phòng, không chuyển phòng sang lớp khác.
 * Lớp chưa bắt đầu học (chờ lịch / sắp khai giảng) chỉ "gán dự kiến": xóa hoặc chuyển phòng thì gỡ phòng khỏi lớp đó.
 */
class RoomService
{
    public function __construct(private readonly SessionScheduleService $schedule) {}

    /**
     * Lớp còn mở đang gắn từng phòng, kèm ca học tuần.
     *
     * @param  iterable<int>  $roomIds
     * @return Collection<int, list<array{id: int, name: string, code: ?string, studying: bool, slots: list<string>}>> theo room_id
     */
    public function usage(iterable $roomIds): Collection
    {
        $ids = collect($roomIds)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return ClassModel::query()
            ->with('scheduleConfig')
            ->whereIn('room_id', $ids)
            ->whereNotIn('status', ClassModel::CLOSED_STATUSES)
            ->orderBy('name')
            ->get()
            ->groupBy('room_id')
            ->map(fn (Collection $classes) => $classes->map(fn (ClassModel $class) => $this->classUsage($class))->values()->all());
    }

    /** @return array{id: int, name: string, code: ?string, studying: bool, slots: list<string>} */
    public function classUsage(ClassModel $class): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'code' => $class->code,
            'studying' => $class->isStudying(),
            'slots' => $this->slotLabels($class),
        ];
    }

    /**
     * Ca học tuần của lớp từ cấu hình TKB.
     *
     * @return list<array{day: string, start: string, end: string}>
     */
    public function slots(ClassModel $class): array
    {
        $config = $class->scheduleConfig;
        if (! $config) {
            return [];
        }

        $slots = [];
        foreach ([1, 2] as $n) {
            $day = $config->{"slot{$n}_day"};
            if ($day && $config->{"slot{$n}_start"} && $config->{"slot{$n}_end"}) {
                $slots[] = ['day' => $day, 'start' => substr($config->{"slot{$n}_start"}, 0, 5), 'end' => substr($config->{"slot{$n}_end"}, 0, 5)];
            }
        }

        return $slots;
    }

    /** "Thứ 2 18:00–19:30"; lớp chưa cấu hình TKB thì dùng mô tả lịch cũ (nếu có). */
    public function slotLabels(ClassModel $class): array
    {
        $slots = array_map(fn (array $slot) => self::slotLabel($slot), $this->slots($class));

        return $slots ?: array_values(array_filter([$class->schedule_text]));
    }

    public static function slotLabel(array $slot): string
    {
        return "{$slot['day']} {$slot['start']}–{$slot['end']}";
    }

    /**
     * Lớp khác đang gắn phòng có ca học tuần chồng giờ với các ca $slots (cùng thứ, giao giờ).
     *
     * @param  list<array{day: string, start: string, end: string}>  $slots
     * @return Collection<int, array{class: ClassModel, slot: string}>
     */
    public function slotConflicts(Room $room, array $slots, ?int $excludeClassId = null): Collection
    {
        return $room->openClasses()
            ->with('scheduleConfig')
            ->when($excludeClassId, fn ($query) => $query->whereKeyNot($excludeClassId))
            ->orderBy('name')
            ->get()
            ->map(function (ClassModel $class) use ($slots) {
                foreach ($this->slots($class) as $theirs) {
                    foreach ($slots as $ours) {
                        if ($theirs['day'] === $ours['day'] && strcmp($theirs['start'], $ours['end']) < 0 && strcmp($theirs['end'], $ours['start']) > 0) {
                            return ['class' => $class, 'slot' => self::slotLabel($theirs)];
                        }
                    }
                }

                return null;
            })
            ->filter()
            ->values();
    }

    /**
     * Gỡ phòng khỏi các lớp (chưa bắt đầu học): lớp về "chưa có phòng", buổi sắp tới đang dùng phòng này cũng bỏ phòng.
     *
     * @param  iterable<ClassModel>  $classes
     */
    public function detach(Room $room, iterable $classes): void
    {
        foreach ($classes as $class) {
            $class->update(['room_id' => null, 'room' => null]);
            ClassSession::where('class_id', $class->id)->where('room', $room->name)->staffSyncable()->update(['room' => null]);
        }
    }

    /** Đổi tên phòng: chép tên mới sang lớp đang gắn phòng và các buổi sắp tới đang dùng tên cũ trong chi nhánh. */
    public function syncName(Room $room, string $oldName): void
    {
        if ($oldName === $room->name) {
            return;
        }

        ClassModel::where('room_id', $room->id)->update(['room' => $room->name]);
        ClassSession::where('branch_id', $room->branch_id)->where('room', $oldName)->staffSyncable()->update(['room' => $room->name]);
    }

    /**
     * Phòng trống của chi nhánh trong khung giờ của một ngày: phòng không có buổi học (chưa hủy) chồng giờ.
     *
     * @return array{rooms: Collection<int, Room>, total: int, holiday: bool}
     */
    public function available(int $branchId, string $date, string $start, string $end, ?int $typeId = null): array
    {
        $rooms = Room::query()
            ->with('type')
            ->where('branch_id', $branchId)
            ->when($typeId, fn ($query) => $query->where('room_type_id', $typeId))
            ->orderBy('name')
            ->get();

        $day = CarbonImmutable::parse($date);
        $holiday = $this->schedule->holidayDates($branchId, $day, $day) !== [];

        $busy = ClassSession::query()
            ->where('branch_id', $branchId)
            ->whereDate('date', $day->toDateString())
            ->where('status', '!=', 'cancelled')
            ->whereIn('room', $rooms->pluck('name'))
            ->get(['room', 'start_time', 'end_time'])
            ->filter(fn (ClassSession $session) => strcmp(substr((string) $session->getRawOriginal('start_time'), 0, 5), $end) < 0
                && strcmp(substr((string) $session->getRawOriginal('end_time'), 0, 5), $start) > 0)
            ->pluck('room')
            ->unique();

        return [
            'rooms' => $rooms->reject(fn (Room $room) => $busy->contains($room->name))->values(),
            'total' => $rooms->count(),
            'holiday' => $holiday,
        ];
    }
}

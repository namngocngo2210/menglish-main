<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Giãn tiến độ lớp học: thêm N buổi chính khóa nối tiếp sau buổi cuối cùng của lớp,
 * theo đúng ca học lặp tuần của lớp (ClassScheduleConfig), bỏ qua ngày nghỉ lễ và
 * chặn khi trùng phòng/nhân sự với lớp khác. Ngày kết thúc lớp được lùi theo buổi mới.
 *
 * Mô hình giáo trình giữ nguyên (giáo trình → bài, chưa gắn bài vào từng buổi), nên
 * "lùi các bài còn lại" tương đương với việc lớp có thêm N buổi trước khi kết thúc.
 */
class ScheduleExtensionService
{
    /** Cửa sổ tìm ngày tối đa (tránh vòng lặp vô hạn khi lịch nghỉ quá dài). */
    private const MAX_SEARCH_DAYS = 366;

    public function __construct(private SessionScheduleService $schedule) {}

    /**
     * @return ClassSession[] các buổi vừa tạo
     *
     * @throws ValidationException
     */
    public function extend(ClassModel $class, int $count, string $note): array
    {
        if ($count < 1) {
            return [];
        }

        $slots = $this->slotsFor($class);
        if ($slots === []) {
            throw ValidationException::withMessages([
                'extra_sessions' => "Lớp {$class->name} chưa có thời khóa biểu (ca học lặp tuần) nên không thể tự thêm buổi. Hãy xếp TKB cho lớp trước.",
            ]);
        }

        $lastDate = ClassSession::where('class_id', $class->id)
            ->where('type', ClassSession::TYPE_REGULAR)
            ->where('status', '!=', 'cancelled')
            ->max('date');
        $anchor = $lastDate ? Carbon::parse($lastDate) : ($class->end_date ? $class->end_date->copy() : today()->subDay());
        $from = $anchor->copy()->addDay()->max(today())->startOfDay();

        $sessions = [];
        $windowDays = (int) ceil($count / count($slots)) * 7 + 7;
        while (count($sessions) < $count && $windowDays <= self::MAX_SEARCH_DAYS) {
            $sessions = $this->schedule->generate($slots, $from, $from->copy()->addDays($windowDays), $class->branch_id);
            $windowDays += 28;
        }
        if (count($sessions) < $count) {
            throw ValidationException::withMessages([
                'extra_sessions' => "Không tìm đủ {$count} buổi trống trong 12 tháng tới theo lịch học của lớp (vướng ngày nghỉ lễ).",
            ]);
        }
        $sessions = array_slice($sessions, 0, $count);

        $conflict = $this->schedule->findConflict(
            array_map(fn (array $session) => $session + ['room' => $class->room], $sessions),
            $class->branch_id,
            [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id],
            $class->room,
            $class->id,
        );
        if ($conflict) {
            [$session, $existing] = $conflict;
            throw ValidationException::withMessages([
                'extra_sessions' => "Buổi thêm {$session['date']} {$session['start']}-{$session['end']} trùng lịch với lớp {$existing->classModel?->name} ({$existing->classModel?->code}). Hãy xếp tay trên TKB.",
            ]);
        }

        return DB::transaction(function () use ($class, $sessions, $note) {
            $created = [];
            foreach ($sessions as $session) {
                $created[] = ClassSession::create([
                    'class_id' => $class->id,
                    'branch_id' => $class->branch_id,
                    'date' => $session['date'],
                    'shift_name' => $session['name'] ?? null,
                    'type' => ClassSession::TYPE_REGULAR,
                    'start_time' => $session['start'],
                    'end_time' => $session['end'],
                    'room' => $class->room,
                    'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                    'assistant_id' => $class->assistant_id,
                    'status' => 'scheduled',
                    'notes' => $note,
                ]);
            }

            $newEnd = Carbon::parse(end($sessions)['date']);
            if (! $class->end_date || $class->end_date->lt($newEnd)) {
                $class->update(['end_date' => $newEnd]);
            }

            return $created;
        });
    }

    /**
     * Ca học lặp tuần của lớp: ưu tiên cấu hình TKB, nếu chưa có thì suy ra từ các buổi chính khóa gần nhất.
     *
     * @return array<int, array{day: string, start: string, end: string, name: string}>
     */
    public function slotsFor(ClassModel $class): array
    {
        $config = $class->scheduleConfig;
        $slots = [];
        if ($config) {
            foreach ([1, 2] as $n) {
                $day = $config->{"slot{$n}_day"};
                if ($day && isset(SessionScheduleService::DAY_MAP[$day])) {
                    $slots[] = [
                        'day' => $day,
                        'start' => substr((string) $config->{"slot{$n}_start"}, 0, 5) ?: '18:00',
                        'end' => substr((string) $config->{"slot{$n}_end"}, 0, 5) ?: '19:30',
                        'name' => "Slot {$n}",
                    ];
                }
            }
        }
        if ($slots !== []) {
            return $slots;
        }

        $dayNames = array_flip(SessionScheduleService::DAY_MAP);
        $recent = ClassSession::where('class_id', $class->id)
            ->where('type', ClassSession::TYPE_REGULAR)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('date')
            ->limit(8)
            ->get();

        foreach ($recent as $session) {
            $day = $dayNames[$session->date->isoWeekday()];
            $start = substr((string) $session->getRawOriginal('start_time'), 0, 5);
            $end = substr((string) $session->getRawOriginal('end_time'), 0, 5);
            $key = "{$day}|{$start}";
            $slots[$key] ??= ['day' => $day, 'start' => $start, 'end' => $end, 'name' => $session->shift_name ?: 'Slot'];
        }

        return array_values($slots);
    }
}

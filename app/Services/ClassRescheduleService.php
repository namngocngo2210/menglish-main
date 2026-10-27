<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassScheduleConfig;
use App\Models\ClassSession;
use App\Services\TeachingShifts\TeachingShiftRules;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Lịch học cố định của lớp (tối đa 2 ca/tuần, lưu ở class_schedule_configs) và việc xếp lại buổi học theo lịch đó.
 * Dùng chung cho màn Lịch & TKB lớp và form Sửa lớp để hai nơi luôn sinh buổi giống nhau.
 *
 * Quy trình: slots() đọc + kiểm tra ca từ input slot{1,2}_{day,start,end} → plan() tính buổi cần sinh (ngoài transaction,
 * để báo lỗi sớm) → apply() trong transaction: kiểm tra trùng GV/phòng, lưu cấu hình, thay buổi, cập nhật schedule_text.
 */
class ClassRescheduleService
{
    public const DAYS = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];

    public const MAX_SESSIONS = 300;

    public function __construct(
        private readonly SessionScheduleService $schedule,
        private readonly TeachingShiftRules $shiftRules,
    ) {}

    /** Luật validate cho các ô slot{1,2}_{day,start,end}. */
    public static function rules(): array
    {
        $day = ['nullable', 'in:'.implode(',', self::DAYS)];

        return [
            'slot1_day' => $day,
            'slot1_start' => ['nullable', 'date_format:H:i'],
            'slot1_end' => ['nullable', 'date_format:H:i', 'after:slot1_start'],
            'slot2_day' => $day,
            'slot2_start' => ['nullable', 'date_format:H:i'],
            'slot2_end' => ['nullable', 'date_format:H:i', 'after:slot2_start'],
        ];
    }

    /**
     * Ca học từ input đã validate: ca không chọn ngày coi như không có; áp khung giờ ca dạy (đặt tên ca) và
     * chặn hai ca trùng ngày chồng giờ.
     *
     * @return list<array{day: string, start: string, end: string, name: string}>
     */
    public function slots(array $payload): array
    {
        // Tên ca tạm; TeachingShiftRules::apply đặt lại theo khung giờ.
        $slots = array_map(fn (array $slot) => $slot + ['name' => 'Slot'], $this->submitted($payload));
        if (empty($slots)) {
            throw ValidationException::withMessages(['slot1_day' => 'Cần chọn ít nhất một ca học (ngày trong tuần).']);
        }
        // Khung giờ ca dạy: Thứ 2–6 chỉ Ca 1 / Ca 2 (giờ chuẩn hoặc giờ lệch), cuối tuần theo giờ lớp; mỗi ca 90 phút.
        // Tên ca (Ca 1, Ca 2, Ca chiều…) đặt theo khung để chấm công GV hiển thị đúng ca được phân công.
        $slots = $this->shiftRules->apply($slots);

        if (count($slots) === 2
            && $slots[0]['day'] === $slots[1]['day']
            && strcmp($slots[0]['start'], $slots[1]['end']) < 0
            && strcmp($slots[1]['start'], $slots[0]['end']) < 0) {
            throw ValidationException::withMessages([
                'slot2_start' => "Hai ca học trùng ngày {$slots[0]['day']} và chồng giờ ({$slots[0]['start']}-{$slots[0]['end']} và {$slots[1]['start']}-{$slots[1]['end']}).",
            ]);
        }

        return $slots;
    }

    /**
     * Ca học thô từ input (chưa áp khung giờ); ca không chọn ngày coi như không có
     * (trước đây Slot 2 trống bị gán mặc định "Thứ 7 18:00-19:30").
     *
     * @return list<array{day: string, start: string, end: string}>
     */
    public function submitted(array $payload): array
    {
        $slots = [];
        foreach ([1, 2] as $n) {
            if (filled($payload["slot{$n}_day"] ?? null)) {
                $slots[] = [
                    'day' => $payload["slot{$n}_day"],
                    'start' => $payload["slot{$n}_start"] ?? '18:00',
                    'end' => $payload["slot{$n}_end"] ?? '19:30',
                ];
            }
        }

        return $slots;
    }

    /** Ca học đang lưu của lớp, cùng dạng với submitted() (chưa có cấu hình → mảng rỗng). */
    public function currentSlots(ClassModel $class): array
    {
        $config = $class->scheduleConfig;
        if (! $config) {
            return [];
        }

        return collect([1, 2])
            ->filter(fn (int $n) => filled($config->{"slot{$n}_day"}))
            ->map(fn (int $n) => [
                'day' => $config->{"slot{$n}_day"},
                'start' => substr((string) $config->{"slot{$n}_start"}, 0, 5),
                'end' => substr((string) $config->{"slot{$n}_end"}, 0, 5),
            ])->values()->all();
    }

    /** Hai bộ ca có cùng thứ + giờ (bỏ qua tên ca) hay không. */
    public function sameSlots(array $a, array $b): bool
    {
        $key = fn (array $slots) => array_map(fn (array $s) => "{$s['day']} {$s['start']}-{$s['end']}", $slots);

        return $key($a) === $key($b);
    }

    /**
     * Buổi cần sinh từ hôm nay (không bao giờ sinh lại buổi quá khứ) đến $end. Buổi đã có dữ liệu thực tế
     * (điểm danh/check-in/đã dạy, phụ đạo, có hẹn học thử) được giữ nguyên và không bị sinh trùng.
     *
     * @return array{sessions: list<array>, replaceable_ids: list<int>}
     */
    public function plan(ClassModel $class, array $slots, CarbonInterface $start, CarbonInterface $end): array
    {
        $generateFrom = $start->copy()->max(now()->startOfDay());
        $replaceableIds = ClassSession::where('class_id', $class->id)->replaceable()->pluck('id')->all();
        $keptSessions = ClassSession::where('class_id', $class->id)
            ->where('type', '!=', ClassSession::TYPE_SUPPORT)
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $generateFrom->toDateString())
            ->whereKeyNot($replaceableIds)
            ->get()
            ->groupBy(fn (ClassSession $session) => $session->date->toDateString());

        $sessions = array_values(array_filter(
            $this->schedule->generate($slots, $generateFrom, $end, $class->branch_id),
            fn (array $session) => ! $keptSessions->get($session['date'], collect())->contains(
                fn (ClassSession $kept) => $kept->shift_name === $session['name']
                    || (strcmp(substr((string) $kept->getRawOriginal('start_time'), 0, 5), $session['end']) < 0
                        && strcmp(substr((string) $kept->getRawOriginal('end_time'), 0, 5), $session['start']) > 0)
            )
        ));

        if (count($sessions) > self::MAX_SESSIONS) {
            throw ValidationException::withMessages([
                'end_date' => 'Thời khóa biểu quá dài ('.count($sessions).' buổi). Giới hạn tối đa '.self::MAX_SESSIONS.' buổi mỗi lần xếp lịch.',
            ]);
        }

        if (empty($sessions) && $keptSessions->isEmpty()) {
            throw ValidationException::withMessages([
                'start_date' => 'Không tạo được buổi học nào. Kiểm tra lại khoảng thời gian (chỉ xếp được từ hôm nay trở đi), ngày học trong tuần và lịch nghỉ lễ.',
            ]);
        }

        return ['sessions' => $sessions, 'replaceable_ids' => $replaceableIds];
    }

    /**
     * Gọi trong transaction: chặn trùng GV/phòng với lớp khác, lưu cấu hình ca, thay các buổi thay được bằng buổi mới
     * (nhân sự lấy theo lớp hiện tại) và cập nhật schedule_text của lớp.
     */
    public function apply(ClassModel $class, array $slots, array $plan, ?string $roomName, string $academicYear): void
    {
        $conflict = $this->schedule->findConflict(
            array_map(fn (array $session) => $session + ['room' => $roomName], $plan['sessions']),
            $class->branch_id,
            [$class->teacher_id, $class->assistant_id, $class->foreign_teacher_id],
            $roomName,
            $class->id,
        );
        if ($conflict) {
            [$session, $existing] = $conflict;
            throw ValidationException::withMessages([
                'class_id' => "Xung đột {$session['date']} {$session['start']}-{$session['end']} với lớp {$existing->classModel?->name} ({$existing->classModel?->code}).",
            ]);
        }

        ClassScheduleConfig::updateOrCreate(
            ['class_id' => $class->id],
            [
                'academic_year' => $academicYear,
                'slot1_day' => $slots[0]['day'],
                'slot1_start' => $slots[0]['start'],
                'slot1_end' => $slots[0]['end'],
                'slot2_day' => $slots[1]['day'] ?? null,
                'slot2_start' => $slots[1]['start'] ?? null,
                'slot2_end' => $slots[1]['end'] ?? null,
            ]
        );

        ClassSession::whereKey($plan['replaceable_ids'])->delete();

        foreach ($plan['sessions'] as $session) {
            ClassSession::create([
                'class_id' => $class->id,
                'branch_id' => $class->branch_id,
                'date' => $session['date'],
                'shift_name' => $session['name'],
                'type' => ClassSession::TYPE_REGULAR,
                'start_time' => $session['start'],
                'end_time' => $session['end'],
                'room' => $roomName,
                'teacher_id' => $class->teacher_id ?? $class->foreign_teacher_id,
                'foreign_teacher_id' => $class->foreign_teacher_id,
                'assistant_id' => $class->assistant_id,
                'status' => 'scheduled',
            ]);
        }

        $class->update(['schedule_text' => self::text($slots)]);
    }

    /** "Thứ 2 Ca 1 18:00-19:30; Thứ 5 Ca 2 19:30-21:00". */
    public static function text(array $slots): string
    {
        return collect($slots)->map(fn (array $slot) => "{$slot['day']} {$slot['name']} {$slot['start']}-{$slot['end']}")->implode('; ');
    }
}

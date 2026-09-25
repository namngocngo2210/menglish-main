<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dữ liệu Dashboard lớp học (theo ngày / ma trận tuần) lấy từ buổi học thật (class_sessions).
 * Nhân sự quản lý lớp thấy mọi buổi; GV/GVNN/TA chỉ thấy buổi của lớp mình hoặc buổi mình dạy.
 */
class ClassDashboardService
{
    public const WEEKDAYS = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];

    /**
     * Buổi học người xem được thấy, lọc theo chi nhánh.
     */
    public function sessionsQuery(User $viewer, ?int $branchId): Builder
    {
        return ClassSession::query()
            ->with([
                'classModel' => fn ($q) => $q->withTrashed()->with(['course:id,name,code', 'branch:id,name']),
                'teacher:id,name', 'foreignTeacher:id,name', 'assistant:id,name', 'holiday:id,name', 'branch:id,name',
                'makeupSession:id,rescheduled_from_id,date',
            ])
            ->withCount('attendances')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when(! ClassModel::userManagesAll($viewer), fn (Builder $q) => $q->where(function (Builder $q) use ($viewer) {
                $q->forStaff($viewer->id)
                    ->orWhereHas('classModel', fn (Builder $class) => $class->where('teacher_id', $viewer->id)
                        ->orWhere('foreign_teacher_id', $viewer->id)
                        ->orWhere('assistant_id', $viewer->id));
            }))
            ->orderBy('date')
            ->orderBy('start_time');
    }

    /**
     * Trạng thái điểm danh của một buổi để hiển thị badge.
     *
     * @return array{label: string, color: string, key: string}
     */
    public function attendanceState(ClassSession $session, CarbonImmutable $today): array
    {
        if ($session->status === 'cancelled') {
            return ['key' => 'cancelled', 'color' => 'neutral',
                'label' => $session->holiday ? 'Nghỉ lễ' : 'Đã hủy'];
        }
        if ($session->attendances_count > 0) {
            return ['key' => 'done', 'color' => 'success', 'label' => 'Đã điểm danh'];
        }
        if ($session->date->gt($today)) {
            return ['key' => 'upcoming', 'color' => 'info', 'label' => 'Chưa diễn ra'];
        }

        return ['key' => 'missing', 'color' => 'error', 'label' => 'Chưa điểm danh'];
    }

    /** Cửa sổ chấm công / điểm danh theo mockup: từ giờ bắt đầu buổi đến 24 giờ sau giờ kết thúc. */
    public const ATTENDANCE_WINDOW_HOURS = 24;

    /**
     * Vị trí hiện tại so với cửa sổ chấm công của buổi: before (chưa tới giờ học), open (trong 24h sau
     * giờ học), closed (quá 24h — vẫn điểm danh bù được, sẽ được Học vụ rà soát).
     */
    public function attendanceWindow(ClassSession $session, ?\Carbon\CarbonInterface $now = null): string
    {
        $now ??= now();
        $date = $session->date->format('Y-m-d');
        $start = \Illuminate\Support\Carbon::parse($date.' '.($session->start_time?->format('H:i') ?? '00:00'));
        $end = \Illuminate\Support\Carbon::parse($date.' '.($session->end_time?->format('H:i') ?? '23:59'));

        return match (true) {
            $now->lt($start) => 'before',
            $now->lte($end->copy()->addHours(self::ATTENDANCE_WINDOW_HOURS)) => 'open',
            default => 'closed',
        };
    }

    /**
     * Trợ giảng có buổi trong ngày cùng khung giờ làm việc (giờ bắt đầu sớm nhất → kết thúc muộn nhất).
     *
     * @return Collection<int, array{user: User, from: string, to: string, sessions: int}>
     */
    public function assistantsOnDuty(Collection $sessions): Collection
    {
        return $sessions
            ->filter(fn (ClassSession $s) => $s->assistant && $s->status !== 'cancelled')
            ->groupBy('assistant_id')
            ->map(fn (Collection $group) => [
                'user' => $group->first()->assistant,
                'from' => $group->min(fn (ClassSession $s) => $s->start_time?->format('H:i')),
                'to' => $group->max(fn (ClassSession $s) => $s->end_time?->format('H:i')),
                'sessions' => $group->count(),
            ])
            ->sortBy('from')
            ->values();
    }

    /**
     * Ma trận tuần: hàng = khung giờ thực tế có buổi, cột = 7 ngày trong tuần.
     *
     * @return array{days: array<int, CarbonImmutable>, rows: array<string, array<int, list<ClassSession>>>}
     */
    public function weekMatrix(Collection $sessions, CarbonImmutable $weekStart): array
    {
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[$i + 1] = $weekStart->addDays($i);
        }

        $rows = [];
        foreach ($sessions as $session) {
            $slot = $session->start_time?->format('H:i').' - '.$session->end_time?->format('H:i');
            $rows[$slot] ??= array_fill(1, 7, []);
            $rows[$slot][$session->date->isoWeekday()][] = $session;
        }
        ksort($rows);

        return ['days' => $days, 'rows' => $rows];
    }

    /**
     * Tuần ISO từ chuỗi "YYYY-Www" (input type=week); sai định dạng thì lấy tuần hiện tại.
     */
    public static function weekStart(?string $week): CarbonImmutable
    {
        if ($week && preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)) {
            return CarbonImmutable::now()->setISODate((int) $m[1], (int) $m[2])->startOfWeek();
        }

        return CarbonImmutable::now()->startOfWeek();
    }

    /**
     * Màu thẻ lớp trong ma trận theo chương trình (ổn định theo tên, không dựa vào chữ "IELTS"/"TOEIC").
     */
    public static function tone(ClassSession $session): string
    {
        if ($session->status === 'cancelled') {
            return 'border-outline-variant bg-surface-container-low text-on-surface-variant line-through';
        }
        if ($session->type === ClassSession::TYPE_MAKEUP) {
            return 'border-amber-300 bg-amber-50 text-amber-900';
        }
        if ($session->type === ClassSession::TYPE_SUPPORT) {
            return 'border-purple-300 bg-purple-50 text-purple-900';
        }
        $tones = [
            'border-orange-200 bg-orange-50 text-orange-900',
            'border-blue-200 bg-blue-50 text-blue-900',
            'border-emerald-200 bg-emerald-50 text-emerald-900',
            'border-sky-200 bg-sky-50 text-sky-900',
        ];
        $key = $session->classModel?->course_id ?? $session->classModel?->program ?? $session->class_id;

        return $tones[abs(crc32((string) $key)) % count($tones)];
    }
}

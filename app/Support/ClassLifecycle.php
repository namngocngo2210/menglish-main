<?php

namespace App\Support;

use App\Models\BigTest;
use App\Models\ClassModel;
use App\Models\ClassSession;

/**
 * Vòng đời một lớp học (Tạo lớp → Cấu hình lịch → Xếp học viên → Khai giảng → Vận hành → Kết thúc), suy ra từ
 * classes.status + sĩ số so với ngưỡng khai giảng. Dùng chung cho Danh sách lớp (cột "Việc tiếp theo") và
 * thanh bước trên Trang lớp, để hai nơi luôn nói cùng một điều.
 */
final class ClassLifecycle
{
    /** Nhãn + màu badge theo trạng thái lớp (thứ tự = thứ tự chip lọc trên Danh sách lớp). */
    public const STATUSES = [
        'pending_schedule' => ['label' => 'Chờ lịch', 'color' => 'warning'],
        'upcoming' => ['label' => 'Sắp khai giảng', 'color' => 'secondary'],
        'active' => ['label' => 'Đang học', 'color' => 'success'],
        'completed' => ['label' => 'Đã kết thúc', 'color' => 'neutral'],
        'cancelled' => ['label' => 'Đã hủy', 'color' => 'error'],
    ];

    /** Tab con của Trang lớp. */
    public const TABS = [
        'overview' => ['label' => 'Tổng quan', 'icon' => 'dashboard'],
        'students' => ['label' => 'Học viên', 'icon' => 'group'],
        'schedule' => ['label' => 'Lịch & buổi học', 'icon' => 'calendar_month'],
        'attendance' => ['label' => 'Điểm danh & báo cáo buổi', 'icon' => 'fact_check'],
        'academic' => ['label' => 'Học thuật', 'icon' => 'school'],
        'incidents' => ['label' => 'Sự vụ', 'icon' => 'report'],
    ];

    /** @return array{label: string, color: string} */
    public static function status(?string $status): array
    {
        return self::STATUSES[$status] ?? ['label' => StatusLabel::for((string) $status), 'color' => 'neutral'];
    }

    /**
     * Các bước vòng đời với trạng thái done / current / todo.
     *
     * @param  array{needed: int, min: int, occupied: int}  $seat  ClassModel::seatSummary()
     * @return list<array{key: string, label: string, state: string, hint: ?string, tab: string}>
     */
    public static function steps(ClassModel $class, array $seat): array
    {
        $status = $class->status;
        $launched = in_array($status, ['active', 'completed'], true);
        $scheduled = $status !== 'pending_schedule';
        $enough = $launched || $seat['needed'] <= 0;

        $steps = [
            ['key' => 'created', 'label' => 'Tạo lớp', 'done' => true, 'hint' => null, 'tab' => 'overview'],
            ['key' => 'schedule', 'label' => 'Cấu hình lịch', 'done' => $scheduled, 'hint' => null, 'tab' => 'schedule'],
            ['key' => 'students', 'label' => 'Xếp học viên', 'done' => $enough && $scheduled,
                'hint' => $seat['occupied'].'/'.$seat['min'].' ngưỡng', 'tab' => 'students'],
            ['key' => 'launch', 'label' => 'Khai giảng', 'done' => $launched, 'hint' => $class->start_date?->format('d/m/Y'), 'tab' => 'overview'],
            ['key' => 'running', 'label' => 'Vận hành', 'done' => $status === 'completed', 'hint' => null, 'tab' => 'attendance'],
            ['key' => 'finished', 'label' => 'Kết thúc', 'done' => $status === 'completed', 'hint' => $class->end_date?->format('d/m/Y'), 'tab' => 'overview'],
        ];

        $currentFound = $status === 'cancelled';
        foreach ($steps as $i => $step) {
            $state = $step['done'] ? 'done' : ($currentFound ? 'todo' : 'current');
            if ($state === 'current') {
                $currentFound = true;
            }
            unset($step['done']);
            $steps[$i] = [...$step, 'state' => $state];
        }

        return $steps;
    }

    /**
     * Việc cần làm tiếp theo cho lớp (cột "Việc tiếp theo" + nút chính trên Trang lớp).
     *
     * @param  array{needed: int}  $seat
     * @return array{label: string, tone: string, tab: string}|null
     */
    public static function nextAction(ClassModel $class, array $seat, ?ClassSession $nextSession = null, ?BigTest $nextBigTest = null): ?array
    {
        return match ($class->status) {
            'pending_schedule' => ['label' => 'Cấu hình lịch', 'tone' => 'error', 'tab' => 'schedule'],
            'upcoming' => $seat['needed'] > 0
                ? ['label' => 'Thiếu '.$seat['needed'].' HV để khai giảng', 'tone' => 'warning', 'tab' => 'students']
                : ['label' => 'Đủ ngưỡng, sẵn sàng khai giảng', 'tone' => 'success', 'tab' => 'overview'],
            'active' => match (true) {
                $nextBigTest && $nextBigTest->scheduled_at?->lte(now()->addDays(14)) => ['label' => 'Big Test '.$nextBigTest->scheduled_at->format('d/m'), 'tone' => 'primary', 'tab' => 'academic'],
                (bool) $nextSession => ['label' => 'Buổi tới '.$nextSession->date->format('d/m'), 'tone' => 'neutral', 'tab' => 'schedule'],
                default => ['label' => 'Chưa có buổi sắp tới', 'tone' => 'warning', 'tab' => 'schedule'],
            },
            default => null,
        };
    }
}

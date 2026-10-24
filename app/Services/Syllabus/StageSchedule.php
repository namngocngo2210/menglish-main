<?php

namespace App\Services\Syllabus;

use App\Models\ClassModel;
use App\Models\ClassSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lịch buổi học của lớp dùng cho chặng (docs/kho-syllabus.md §7.3): buổi chính khóa + học bù, không hủy, từ ngày bắt đầu.
 * Ngày dự kiến kết thúc chặng = ngày của buổi thứ N (N = số buổi Học thuật nhập khi giao chặng).
 */
class StageSchedule
{
    /** @return Collection<int, Carbon> ngày các buổi học từ $from (theo ngày, giờ học) */
    public function sessionDates(ClassModel $class, CarbonInterface $from): Collection
    {
        return ClassSession::query()
            ->where('class_id', $class->id)
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])
            ->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $from->toDateString())
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->values();
    }

    /** Ngày buổi học thứ $sessions tính từ $from; null khi TKB của lớp chưa đủ buổi. */
    public function endDate(ClassModel $class, CarbonInterface $from, int $sessions): ?Carbon
    {
        return $sessions > 0 ? $this->sessionDates($class, $from)->get($sessions - 1) : null;
    }
}

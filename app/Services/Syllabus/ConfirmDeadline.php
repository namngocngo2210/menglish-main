<?php

namespace App\Services\Syllabus;

use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Services\Sla\Sla;
use Carbon\CarbonInterface;

/**
 * Hạn GV XÁC NHẬN chặng (docs/luong-giao-vien.md): N giờ (SLA gv.syllabus_confirm, mặc định 1) trước buổi học đầu tiên của chặng
 * — buổi thường / học bù chưa hủy, từ ngày bắt đầu chặng. Giao chặng sát giờ (đã qua mốc của buổi đó) → tính theo buổi kế tiếp,
 * để GV luôn có thời gian xác nhận. Lớp chưa có buổi phù hợp → null.
 */
class ConfirmDeadline
{
    public function dueAt(SyllabusAssignment $assignment): ?CarbonInterface
    {
        $assignedAt = $assignment->assigned_at ?? $assignment->opened_at ?? $assignment->created_at;
        $from = $assignment->start_date ?? $assignment->opened_at;
        if (! $assignment->class_id || ! $assignedAt || ! $from) {
            return null;
        }
        $hours = Sla::value('gv.syllabus_confirm');

        foreach (ClassSession::where('class_id', $assignment->class_id)
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', max($from->toDateString(), $assignedAt->toDateString()))
            ->orderBy('date')->orderBy('start_time')->lazy() as $session) {
            $due = $session->date->copy()->setTimeFromTimeString($session->start_time?->format('H:i:s') ?? '00:00:00')->subHours($hours);
            if ($due->gte($assignedAt)) {
                return $due;
            }
        }

        return null;
    }
}

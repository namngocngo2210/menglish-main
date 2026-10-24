<?php

namespace App\Console\Commands;

use App\Models\SyllabusAssignment;
use App\Services\Syllabus\StageSchedule;
use Illuminate\Console\Command;

/**
 * Tính lại ngày dự kiến kết thúc của chặng đang mở theo TKB hiện tại (hủy buổi, nghỉ lễ, học bù, giãn tiến độ làm lịch đổi —
 * docs/kho-syllabus.md §7.3). Idempotent.
 */
class RefreshStageDeadlinesCommand extends Command
{
    protected $signature = 'syllabus:refresh-stage-deadlines';

    protected $description = 'Tính lại ngày dự kiến kết thúc chặng đang mở theo thời khóa biểu của lớp';

    public function handle(StageSchedule $schedule): int
    {
        $changed = 0;
        SyllabusAssignment::open()->with('classModel')
            ->whereNotNull('start_date')->whereNotNull('planned_sessions')
            ->orderBy('id')
            ->each(function (SyllabusAssignment $assignment) use ($schedule, &$changed) {
                if (! $assignment->classModel) {
                    return;
                }
                $end = $schedule->endDate($assignment->classModel, $assignment->start_date, $assignment->planned_sessions);
                if ($end?->toDateString() !== $assignment->deadline?->toDateString()) {
                    $assignment->update(['deadline' => $end]);
                    $changed++;
                }
            });

        $this->info("Đã cập nhật ngày dự kiến kết thúc của {$changed} chặng.");

        return self::SUCCESS;
    }
}

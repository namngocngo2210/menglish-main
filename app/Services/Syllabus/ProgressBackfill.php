<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonProgress;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển lớp đang chạy sang tiến độ theo "Đã dạy" (docs/kho-syllabus.md §11): chặng đang mở chưa có dòng tiến độ nào → đánh
 * dấu Đã dạy N buổi đầu của chặng, N = số buổi học đã qua kể từ ngày mở chặng (cách đếm cũ), tối đa số buổi của chặng.
 * Idempotent: chặng đã có dòng tiến độ thì bỏ qua.
 */
class ProgressBackfill
{
    public function __construct(private readonly StageSchedule $schedule) {}

    public function run(): int
    {
        $count = 0;
        SyllabusAssignment::open()->whereNotNull('stage_id')->whereNotNull('class_id')
            ->whereDoesntHave('progress')
            ->orderBy('id')
            ->each(function (SyllabusAssignment $assignment) use (&$count) {
                $from = ($assignment->start_date ?? $assignment->opened_at ?? $assignment->created_at)->copy()->startOfDay();
                $sessions = $this->schedule->sessions($assignment->class_id, $from)->filter(fn ($s) => $s->date->lt(today()))->values();
                $lessons = SyllabusLesson::whereHas('unit', fn ($q) => $q->where('stage_id', $assignment->stage_id))->orderBy('session_no')->pluck('id');
                $rows = [];
                foreach ($lessons->take($sessions->count()) as $i => $lessonId) {
                    $rows[] = [
                        'syllabus_assignment_id' => $assignment->id, 'class_id' => $assignment->class_id, 'syllabus_lesson_id' => $lessonId,
                        'class_session_id' => $sessions[$i]->id, 'kind' => ClassLessonProgress::KIND_LESSON, 'taught_by' => $assignment->user_id,
                        'taught_at' => $sessions[$i]->date->copy()->setTime(12, 0), 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                if ($rows !== []) {
                    DB::table('class_lesson_progress')->insert($rows);
                    $count += count($rows);
                }
            });

        return $count;
    }
}

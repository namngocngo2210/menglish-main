<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonProgress;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển lớp đang chạy sang tiến độ theo "Đã dạy" (docs/kho-syllabus.md §11): chặng đang mở chưa có dòng tiến độ nào → đánh
 * dấu Đã dạy N buổi đầu của chặng, N = số buổi học đã qua kể từ ngày mở chặng (cách đếm cũ), tối đa số buổi của chặng; buổi
 * đã qua vượt quá số buổi của chặng ghi là buổi ôn (extra) để không còn nhắc "chưa đánh dấu".
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
                foreach ($sessions as $i => $session) {
                    // Buổi vượt quá số buổi của chặng = buổi ôn / Big Test (dòng extra, không gắn buổi syllabus).
                    $lessonId = $lessons[$i] ?? null;
                    $rows[] = [
                        'syllabus_assignment_id' => $assignment->id, 'class_id' => $assignment->class_id, 'syllabus_lesson_id' => $lessonId,
                        'class_session_id' => $session->id, 'kind' => $lessonId ? ClassLessonProgress::KIND_LESSON : ClassLessonProgress::KIND_EXTRA,
                        'taught_by' => $assignment->user_id, 'taught_at' => $session->date->copy()->setTime(12, 0), 'created_at' => now(), 'updated_at' => now(),
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

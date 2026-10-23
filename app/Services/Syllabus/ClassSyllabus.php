<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonOverride;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use Illuminate\Support\Collection;

/**
 * Syllabus hiệu lực của lớp (docs/kho-syllabus.md §6.2): buổi gốc của chặng lớp đang học ghép bản đè của lớp. Một chỗ duy
 * nhất cho tab Syllabus của lớp, màn GV, ORDER, cảnh báo cuối chặng, quyền Google Drive.
 */
class ClassSyllabus
{
    public function __construct(private readonly StageSchedule $schedule) {}

    /**
     * @return Collection<int, array{lesson: SyllabusLesson, data: array<string, mixed>, overridden: bool, override_reason: ?string, override: ?ClassLessonOverride, unit_number: ?int, unit_title: ?string}>
     */
    public function lessons(SyllabusAssignment $assignment): Collection
    {
        if (! $assignment->stage_id) {
            return collect();
        }
        $lessons = SyllabusLesson::with('unit:id,unit_number,title,stage_id')
            ->whereHas('unit', fn ($q) => $q->where('stage_id', $assignment->stage_id))
            ->orderBy('session_no')
            ->get();
        $overrides = ClassLessonOverride::with('editor:id,name')
            ->where('class_id', $assignment->class_id)
            ->whereIn('syllabus_lesson_id', $lessons->pluck('id'))
            ->get()->keyBy('syllabus_lesson_id');

        return $lessons->map(function (SyllabusLesson $lesson) use ($overrides) {
            $override = $overrides->get($lesson->id);

            return [
                'lesson' => $lesson,
                'data' => $this->effective($lesson, $override),
                'overridden' => $override !== null,
                'override_reason' => $override?->reason,
                'override' => $override,
                'unit_number' => $lesson->unit?->unit_number,
                'unit_title' => $lesson->unit?->title,
            ];
        })->values();
    }

    /**
     * Tiến độ + lịch dự kiến của chặng (docs/kho-syllabus.md §8.2). Buổi hiện tại = buổi đầu chưa bấm Đã dạy. Lịch dự kiến: buổi
     * học từ hôm nay chưa gắn tiến độ xếp cho buổi giãn còn lại trước, rồi các buổi syllabus chưa dạy (buổi hủy không có trong TKB
     * nên lịch tự dời).
     *
     * @return array{rows: Collection<int, array>, current: ?array, taught: int, extra_left: int, unmarked_sessions: Collection<int, ClassSession>}
     */
    public function timeline(SyllabusAssignment $assignment): array
    {
        $progress = ClassLessonProgress::where('syllabus_assignment_id', $assignment->id)->get();
        $byLesson = $progress->where('kind', ClassLessonProgress::KIND_LESSON)->keyBy('syllabus_lesson_id');
        $extraLeft = max(0, (int) $assignment->extra_sessions - $progress->where('kind', ClassLessonProgress::KIND_EXTRA)->count());
        $markedSessions = $progress->pluck('class_session_id')->filter()->flip();

        $from = ($assignment->start_date ?? $assignment->opened_at ?? $assignment->created_at ?? today())->copy()->startOfDay();
        $sessions = $assignment->class_id ? $this->schedule->sessions($assignment->class_id, $from) : collect();
        $open = $sessions->reject(fn (ClassSession $s) => $markedSessions->has($s->id));
        $unmarked = $open->filter(fn (ClassSession $s) => $s->date->lt(today()))->values();
        $upcoming = $open->filter(fn (ClassSession $s) => $s->date->gte(today()))->values()->slice($extraLeft)->values();

        $next = 0;
        $rows = $this->lessons($assignment)->map(function (array $row) use ($byLesson, $upcoming, &$next) {
            $mark = $byLesson->get($row['lesson']->id);
            $planned = $mark ? null : $upcoming->get($next++);

            return $row + [
                'taught_at' => $mark?->taught_at,
                'taught_session_id' => $mark?->class_session_id,
                'pace_note' => $mark?->pace_note,
                'planned_date' => $planned?->date?->copy(),
                'planned_session_id' => $planned?->id,
            ];
        });

        return [
            'rows' => $rows,
            'current' => $rows->first(fn (array $row) => $row['taught_at'] === null),
            'taught' => $byLesson->count(),
            'extra_left' => $extraLeft,
            'unmarked_sessions' => $unmarked,
        ];
    }

    /** @return array<string, mixed> các trường ClassLessonOverride::FIELDS sau khi ghép bản đè */
    public function effective(SyllabusLesson $lesson, ?ClassLessonOverride $override): array
    {
        $data = $lesson->only(ClassLessonOverride::FIELDS);

        return array_merge($data, array_intersect_key($override?->data ?? [], $data));
    }
}

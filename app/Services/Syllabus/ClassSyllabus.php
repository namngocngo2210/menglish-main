<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonOverride;
use App\Models\ClassLessonProgress;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Syllabus hiệu lực của lớp (docs/kho-syllabus.md §6.2): buổi gốc của chặng lớp đang học ghép bản đè của lớp. Một chỗ duy
 * nhất cho tab Syllabus của lớp, màn GV, ORDER, cảnh báo cuối chặng.
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
     * học từ hôm nay chưa gắn tiến độ xếp lần lượt cho các buổi syllabus chưa dạy (buổi giãn dùng sau, khi GV bấm "Dạy chưa xong";
     * buổi hủy không có trong TKB nên lịch tự dời).
     *
     * @return array{rows: Collection<int, array>, current: ?array, taught: int, extra_left: int, unmarked_sessions: Collection<int, ClassSession>}
     */
    public function timeline(SyllabusAssignment $assignment): array
    {
        $progress = ClassLessonProgress::where('syllabus_assignment_id', $assignment->id)->get();
        $sessions = $assignment->class_id ? $this->schedule->sessions($assignment->class_id, self::startOf($assignment)) : collect();
        $lessons = $this->lessons($assignment);
        $plan = self::plan($lessons->pluck('lesson'), $progress, $sessions);

        $rows = $lessons->map(fn (array $row, int $i) => $row + $plan['rows'][$i]);

        return [
            'rows' => $rows,
            'current' => $rows->first(fn (array $row) => $row['taught_at'] === null),
            'taught' => $plan['taught'],
            'extra_left' => max(0, (int) $assignment->extra_sessions - $progress->where('kind', ClassLessonProgress::KIND_EXTRA)->count()),
            'unmarked_sessions' => $plan['unmarked'],
        ];
    }

    /**
     * Buổi học ↔ buổi syllabus cho nhiều chặng đang mở cùng lúc (TKB, giao bài tập, báo cáo trực lớp): truy vấn gộp, không ghép
     * bản đè. Buổi đã qua theo dòng Đã dạy, buổi sắp tới theo lịch dự kiến.
     *
     * @param  Collection<int, SyllabusAssignment>  $assignments
     * @return array<int, array{start: Carbon, sessions: array<int, SyllabusLesson>}> class_id => mốc bắt đầu + [session_id => buổi]
     */
    public function sessionLessonMap(Collection $assignments): array
    {
        $assignments = $assignments->filter(fn (SyllabusAssignment $a) => $a->stage_id && $a->class_id);
        if ($assignments->isEmpty()) {
            return [];
        }
        $progress = ClassLessonProgress::whereIn('syllabus_assignment_id', $assignments->modelKeys())->get()->groupBy('syllabus_assignment_id');
        $from = $assignments->map(fn ($a) => self::startOf($a))->min();
        $sessions = ClassSession::query()->whereIn('class_id', $assignments->pluck('class_id'))
            ->whereIn('type', [ClassSession::TYPE_REGULAR, ClassSession::TYPE_MAKEUP])->where('status', '!=', 'cancelled')
            ->whereDate('date', '>=', $from->toDateString())
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get(['id', 'class_id', 'date', 'start_time'])->groupBy('class_id');
        $lessons = SyllabusLesson::with('unit:id,unit_number,title,stage_id')
            ->whereHas('unit', fn ($q) => $q->whereIn('stage_id', $assignments->pluck('stage_id')->unique()))
            ->orderBy('session_no')->get()->groupBy(fn (SyllabusLesson $l) => $l->unit?->stage_id);

        $result = [];
        foreach ($assignments as $assignment) {
            $start = self::startOf($assignment);
            $classSessions = ($sessions->get($assignment->class_id) ?? collect())->filter(fn ($s) => $s->date->gte($start))->values();
            $stageLessons = ($lessons->get($assignment->stage_id) ?? collect())->values();
            $plan = self::plan($stageLessons, $progress->get($assignment->id) ?? collect(), $classSessions);
            $map = [];
            foreach ($plan['rows'] as $i => $row) {
                if ($sessionId = $row['taught_session_id'] ?? $row['planned_session_id']) {
                    $map[$sessionId] = $stageLessons[$i];
                }
            }
            $result[(int) $assignment->class_id] = ['start' => $start, 'sessions' => $map];
        }

        return $result;
    }

    public static function startOf(SyllabusAssignment $assignment): Carbon
    {
        return Carbon::parse($assignment->start_date ?? $assignment->opened_at ?? $assignment->created_at ?? today())->startOfDay();
    }

    /**
     * Lõi lịch dự kiến (dùng chung timeline / sessionLessonMap).
     *
     * @param  Collection<int, SyllabusLesson>  $lessons  buổi của chặng theo session_no
     * @param  Collection<int, ClassLessonProgress>  $progress  dòng Đã dạy của chặng
     * @param  Collection<int, ClassSession>  $sessions  buổi học từ ngày bắt đầu chặng
     * @return array{rows: list<array>, taught: int, unmarked: Collection<int, ClassSession>}
     */
    private static function plan(Collection $lessons, Collection $progress, Collection $sessions): array
    {
        $byLesson = $progress->where('kind', ClassLessonProgress::KIND_LESSON)->keyBy('syllabus_lesson_id');
        $marked = $progress->pluck('class_session_id')->filter()->flip();
        $open = $sessions->reject(fn (ClassSession $s) => $marked->has($s->id));
        $upcoming = $open->filter(fn (ClassSession $s) => $s->date->gte(today()))->values();

        $next = 0;
        $rows = [];
        foreach ($lessons->values() as $lesson) {
            $mark = $byLesson->get($lesson->id);
            $planned = $mark ? null : $upcoming->get($next++);
            $rows[] = [
                'taught_at' => $mark?->taught_at,
                'taught_session_id' => $mark?->class_session_id,
                'pace_note' => $mark?->pace_note,
                'planned_date' => $planned?->date?->copy(),
                'planned_session_id' => $planned?->id,
            ];
        }

        return ['rows' => $rows, 'taught' => $byLesson->count(), 'unmarked' => $open->filter(fn (ClassSession $s) => $s->date->lt(today()))->values()];
    }

    /** @return array<string, mixed> các trường ClassLessonOverride::FIELDS sau khi ghép bản đè */
    public function effective(SyllabusLesson $lesson, ?ClassLessonOverride $override): array
    {
        $data = $lesson->only(ClassLessonOverride::FIELDS);

        return array_merge($data, array_intersect_key($override?->data ?? [], $data));
    }
}

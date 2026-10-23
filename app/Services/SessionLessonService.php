<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\SyllabusUnit;
use App\Services\Syllabus\ClassSyllabus;
use Illuminate\Support\Collection;

/**
 * Nội dung bài học của từng buổi học trong TKB: lớp có chặng đang mở → buổi đã qua theo nút "Đã dạy", buổi sắp tới theo lịch
 * dự kiến của chặng (docs/kho-syllabus.md §8.2); lớp chưa mở chặng → buổi chính khóa thứ N ↔ buổi `session_no` N (cách cũ). Giáo trình của lớp = chặng đang mở
 * (SyllabusAssignment) hoặc giáo trình gắn với trình độ của lớp.
 */
class SessionLessonService
{
    /** @var array<int, int|null> class_id => curriculum_id */
    private array $curricula = [];

    /**
     * @param  Collection<int, ClassSession>  $sessions
     * @return array<int, array{no: int, unit: ?string, title: ?string, summary: ?string}> session_id => nội dung
     *                                                                                     (`summary`: bài + từ vựng / ngữ pháp trọng tâm — điền sẵn ô "Hôm nay học gì" của báo cáo trực lớp)
     */
    public function lessonsFor(Collection $sessions): array
    {
        $classIds = $sessions->pluck('class_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
        if ($classIds === []) {
            return [];
        }

        $numbers = $this->sessionNumbers($classIds);
        // Lớp có chặng đang mở: buổi đã qua theo dòng "Đã dạy", buổi sắp tới theo lịch dự kiến (docs/kho-syllabus.md §8.2).
        $planned = $this->plannedLessons($classIds);
        $curricula = $this->curriculaFor($classIds);
        $lessons = SyllabusLesson::with('unit:id,unit_number,title')
            ->whereIn('curriculum_id', array_filter(array_unique(array_values($curricula))))
            ->get()
            ->keyBy(fn (SyllabusLesson $l) => $l->curriculum_id.'-'.$l->session_no);

        $result = [];
        foreach ($sessions as $session) {
            $no = $numbers[$session->id] ?? null;
            $byPlan = array_key_exists((int) $session->class_id, $planned);
            if (! $no && ! isset($planned[(int) $session->class_id][$session->id])) {
                continue;
            }
            $lesson = $byPlan
                ? ($planned[(int) $session->class_id][$session->id] ?? null)
                : $lessons->get(($curricula[$session->class_id] ?? 0).'-'.$no);
            $result[$session->id] = [
                'no' => $no ?? $lesson?->session_no,
                'unit' => $lesson?->unit ? 'Unit '.$lesson->unit->unit_number.': '.$lesson->unit->title : null,
                'title' => $lesson?->title ?: ($lesson?->grammar_focus ?: null),
                'summary' => $lesson ? collect([
                    $lesson->unit ? 'Unit '.$lesson->unit->unit_number.': '.$lesson->unit->title : null,
                    $lesson->title ? 'Bài: '.$lesson->title : null,
                    filled($lesson->vocabulary_focus) ? 'Từ vựng: '.trim($lesson->vocabulary_focus) : null,
                    filled($lesson->grammar_focus) ? 'Ngữ pháp: '.trim($lesson->grammar_focus) : null,
                ])->filter()->implode("\n") ?: null : null,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int>  $classIds
     * @return array<int, array<int, SyllabusLesson>> class_id => [session_id => buổi syllabus] cho lớp có chặng đang mở
     */
    private function plannedLessons(array $classIds): array
    {
        $result = [];
        $timeline = app(ClassSyllabus::class);
        SyllabusAssignment::open()->whereIn('class_id', $classIds)->whereNotNull('stage_id')->get()
            ->each(function (SyllabusAssignment $assignment) use ($timeline, &$result) {
                $map = [];
                foreach ($timeline->timeline($assignment)['rows'] as $row) {
                    $sessionId = $row['taught_session_id'] ?? $row['planned_session_id'];
                    if ($sessionId) {
                        $map[$sessionId] = $row['lesson'];
                    }
                }
                $result[(int) $assignment->class_id] = $map;
            });

        return $result;
    }

    /**
     * Unit của giáo trình lớp (cho ô "Chọn Unit" khi nhập điểm mini test).
     *
     * @return Collection<int, SyllabusUnit>
     */
    public function unitsForClass(ClassModel $class): Collection
    {
        $curriculumId = $this->curriculaFor([(int) $class->id])[(int) $class->id] ?? null;

        return $curriculumId
            ? SyllabusUnit::where('curriculum_id', $curriculumId)->orderBy('unit_number')->get(['id', 'unit_number', 'title'])
            : collect();
    }

    /**
     * Số thứ tự buổi chính khóa (1..n) theo lớp.
     *
     * @param  array<int>  $classIds
     * @return array<int, int> session_id => số buổi
     */
    public function sessionNumbers(array $classIds): array
    {
        $numbers = [];
        ClassSession::query()
            ->whereIn('class_id', $classIds)
            ->where('type', ClassSession::TYPE_REGULAR)
            ->where('status', '!=', 'cancelled')
            ->orderBy('date')->orderBy('start_time')->orderBy('id')
            ->get(['id', 'class_id'])
            ->groupBy('class_id')
            ->each(function (Collection $rows) use (&$numbers) {
                foreach ($rows->values() as $i => $row) {
                    $numbers[$row->id] = $i + 1;
                }
            });

        return $numbers;
    }

    /**
     * @param  array<int>  $classIds
     * @return array<int, int|null>
     */
    private function curriculaFor(array $classIds): array
    {
        $missing = array_diff($classIds, array_keys($this->curricula));
        if ($missing !== []) {
            $open = SyllabusAssignment::open()->whereIn('class_id', $missing)->pluck('curriculum_id', 'class_id');
            $levels = ClassModel::withTrashed()->whereIn('id', $missing)->pluck('level', 'id');
            $byLevel = CourseLevel::whereIn('code', $levels->filter()->unique())->pluck('syllabus_curriculum_id', 'code');
            foreach ($missing as $classId) {
                $this->curricula[$classId] = $open[$classId] ?? ($byLevel[$levels[$classId] ?? ''] ?? null);
            }
        }

        return array_intersect_key($this->curricula, array_flip($classIds));
    }
}

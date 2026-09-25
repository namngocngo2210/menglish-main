<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CourseLevel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\SyllabusUnit;
use Illuminate\Support\Collection;

/**
 * Nội dung bài học của từng buổi học trong TKB (Q4): buổi chính khóa thứ N của lớp (không tính buổi hủy /
 * học bù / phụ đạo) ↔ buổi `session_no` N của giáo trình lớp đang học. Giáo trình của lớp = chặng đang mở
 * (SyllabusAssignment) hoặc giáo trình gắn với trình độ của lớp.
 */
class SessionLessonService
{
    /** @var array<int, int|null> class_id => curriculum_id */
    private array $curricula = [];

    /**
     * @param  Collection<int, ClassSession>  $sessions
     * @return array<int, array{no: int, unit: ?string, title: ?string}> session_id => nội dung
     */
    public function lessonsFor(Collection $sessions): array
    {
        $classIds = $sessions->pluck('class_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
        if ($classIds === []) {
            return [];
        }

        $numbers = $this->sessionNumbers($classIds);
        $curricula = $this->curriculaFor($classIds);
        $lessons = SyllabusLesson::with('unit:id,unit_number,title')
            ->whereIn('curriculum_id', array_filter(array_unique(array_values($curricula))))
            ->get()
            ->keyBy(fn (SyllabusLesson $l) => $l->curriculum_id.'-'.$l->session_no);

        $result = [];
        foreach ($sessions as $session) {
            $no = $numbers[$session->id] ?? null;
            if (! $no) {
                continue;
            }
            $lesson = $lessons->get(($curricula[$session->class_id] ?? 0).'-'.$no);
            $result[$session->id] = [
                'no' => $no,
                'unit' => $lesson?->unit ? 'Unit '.$lesson->unit->unit_number.': '.$lesson->unit->title : null,
                'title' => $lesson?->title ?: ($lesson?->grammar_focus ?: null),
            ];
        }

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

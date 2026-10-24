<?php

namespace App\Services\Syllabus;

use App\Models\ClassLessonOverride;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use Illuminate\Support\Collection;

/**
 * Syllabus hiệu lực của lớp (docs/kho-syllabus.md §6.2): buổi gốc của chặng lớp đang học ghép bản đè của lớp. Một chỗ duy
 * nhất cho tab Syllabus của lớp, màn GV, ORDER, cảnh báo cuối chặng, quyền Google Drive.
 */
class ClassSyllabus
{
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

    /** @return array<string, mixed> các trường ClassLessonOverride::FIELDS sau khi ghép bản đè */
    public function effective(SyllabusLesson $lesson, ?ClassLessonOverride $override): array
    {
        $data = $lesson->only(ClassLessonOverride::FIELDS);

        return array_merge($data, array_intersect_key($override?->data ?? [], $data));
    }
}

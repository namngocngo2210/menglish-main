<?php

namespace App\Services\Syllabus;

use App\Models\ClassModel;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Models\User;
use App\Support\Ui;

/** Dữ liệu tab "Syllabus" của trang lớp (docs/kho-syllabus.md §7.3 bước 5): chặng đang học, xác nhận GV, buổi hiệu lực. */
class ClassSyllabusTab
{
    public function __construct(private readonly ClassSyllabus $syllabus) {}

    /** @return array<string, mixed> */
    public function data(ClassModel $class, User $viewer): array
    {
        $assignment = SyllabusAssignment::with(['stage', 'curriculum:id,title', 'confirmer:id,name', 'teacher:id,name'])
            ->open()->where('class_id', $class->id)->first();

        return [
            'assignment_id' => $assignment?->id,
            'stage_label' => $assignment ? ($assignment->stage?->label ?? $assignment->stage_name) : null,
            'curriculum' => $assignment?->curriculum?->title,
            'teacher' => $assignment?->teacher?->name,
            'start_date' => $assignment?->start_date?->toDateString(),
            'planned_sessions' => $assignment?->planned_sessions,
            'deadline' => $assignment?->deadline?->toDateString(),
            'confirmed' => (bool) $assignment?->isConfirmed(),
            'confirmed_at' => $assignment?->confirmed_at?->toIso8601String(),
            'confirmed_by' => $assignment?->confirmer?->name,
            'remind_url' => $assignment && ! $assignment->isConfirmed() && $viewer->can('syllabus.assign')
                ? route('syllabus.assignments.remind', $assignment->id) : null,
            'lessons' => $assignment ? $this->syllabus->lessons($assignment)->map(fn (array $row) => [
                'id' => $row['lesson']->id,
                'session_no' => $row['lesson']->session_no,
                'unit_number' => $row['unit_number'],
                'unit_title' => $row['unit_title'],
                'type_label' => SyllabusLesson::TYPES[$row['data']['lesson_type']] ?? SyllabusLesson::TYPES['lesson'],
                'milestone_label' => SyllabusLesson::MILESTONES[$row['data']['notice_milestone']] ?? null,
                'data' => $row['data'],
                'overridden' => $row['overridden'],
                'override_reason' => $row['override_reason'],
                'override_by' => $row['override']?->editor?->name,
                'override_at' => $row['override']?->updated_at?->toIso8601String(),
            ])->values()->all() : [],
            'can_edit' => $viewer->can('syllabus.class_edit'),
            'lesson_types' => Ui::options(SyllabusLesson::TYPES),
            'milestones' => Ui::options(SyllabusLesson::MILESTONES),
        ];
    }
}

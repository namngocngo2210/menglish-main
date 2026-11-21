<?php

namespace App\Http\Controllers;

use App\Models\AcademicObservation;
use App\Models\BigTest;
use App\Models\ClassEnrollment;
use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\SyllabusAssignment;
use App\Services\Syllabus\ClassSyllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AcademicClassOverviewController extends Controller
{
    public function __invoke(Request $request, ClassSyllabus $syllabus): Response
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'program' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
        ]);
        $base = ClassModel::visibleTo($request->user())->whereNotIn('status', ClassModel::CLOSED_STATUSES);

        // All summaries and filter choices share the exact same personal/data scope as the table.
        $branches = (clone $base)->select('branch_id')->selectRaw('COUNT(*) AS total')
            ->whereNotNull('branch_id')->groupBy('branch_id')->with('branch:id,name')->get()
            ->map(fn ($group) => ['value' => $group->branch_id, 'label' => $group->branch?->name ?? 'Chưa có cơ sở', 'count' => (int) $group->total])
            ->sortBy('label')->values();
        $groups = ['branches' => $branches];
        foreach (['program' => 'programs', 'level' => 'levels'] as $column => $key) {
            $groups[$key] = (clone $base)->select($column)->selectRaw('COUNT(*) AS total')
                ->whereNotNull($column)->where($column, '!=', '')->groupBy($column)->orderBy($column)->get()
                ->map(fn ($group) => ['value' => $group->$column, 'label' => $group->$column, 'count' => (int) $group->total]);
        }

        $query = clone $base;
        foreach (['branch_id', 'program', 'level'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        $classes = $query->with(['branch:id,name', 'teacher:id,name'])->orderBy('code')->orderBy('id')
            ->paginate(15, ['id', 'code', 'name', 'branch_id', 'program', 'level', 'teacher_id', 'max_capacity', 'min_students'])
            ->withQueryString();
        $ids = $classes->getCollection()->modelKeys();
        ClassModel::loadRosterCounts($classes);

        // Union the two roster sources, then cap each class in SQL: one batched student-preview query.
        $memberships = Student::query()->whereIn('current_class_id', $ids)
            ->selectRaw('id AS student_id, current_class_id AS class_id')
            ->union(ClassEnrollment::query()->whereIn('class_id', $ids)
                ->whereIn('status', ClassModel::ACTIVE_ENROLLMENT_STATUSES)->select('student_id', 'class_id'));
        $ranked = Student::query()->joinSub($memberships, 'memberships', 'memberships.student_id', '=', 'students.id')
            ->whereIn('students.status', ClassModel::SEAT_HOLDING_STUDENT_STATUSES)
            ->select('students.id', 'students.name', 'students.code', 'memberships.class_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY memberships.class_id ORDER BY students.name, students.id) AS roster_position');
        $students = DB::query()->fromSub($ranked, 'roster')->where('roster_position', '<=', 50)
            ->orderBy('class_id')->orderBy('roster_position')->get()->groupBy('class_id');

        $assignments = SyllabusAssignment::open()->whereIn('class_id', $ids)
            ->with(['stage.units.lessons', 'progress'])->get()->keyBy('class_id');
        // Reuse the planner behind the class academic tab, including taught lessons, makeup and cancelled sessions.
        $lessonMaps = $syllabus->sessionLessonMap($assignments->values());
        $sessionIds = collect($lessonMaps)->flatMap(fn ($map) => array_keys($map['sessions']))->unique();
        $dates = ClassSession::whereIn('id', $sessionIds)->get(['id', 'date'])->keyBy('id');
        $observations = AcademicObservation::whereIn('class_id', $ids)->orderByDesc('month')->orderByDesc('id')
            ->get(['class_id', 'month', 'observed', 'observed_on'])->groupBy('class_id')->map->first();
        $tests = BigTest::whereIn('class_id', $ids)->whereNotNull('scheduled_at')->orderBy('scheduled_at')->orderBy('id')
            ->get(['class_id', 'syllabus_stage_id', 'scheduled_at'])->groupBy('class_id');

        return Inertia::render('Academic/ClassOverview', [
            'filters' => $filters,
            'groups' => $groups,
            'classes' => $classes->through(function (ClassModel $class) use ($students, $assignments, $lessonMaps, $dates, $observations, $tests) {
                $assignment = $assignments->get($class->id);
                $lessons = $assignment?->stage?->units->flatMap->lessons->sortBy('session_no')->values() ?? collect();
                $taughtIds = $assignment?->progress->where('kind', ClassLessonProgress::KIND_LESSON)->pluck('syllabus_lesson_id') ?? collect();
                // Match the academic tab: current lesson is the first not yet marked taught (last lesson if all taught).
                $current = $lessons->first(fn ($lesson) => ! $taughtIds->contains($lesson->id)) ?? $lessons->last();
                $unit = $assignment?->stage?->units->firstWhere('id', $current?->unit_id);
                $lastLesson = $lessons->last();
                $expected = null;
                // Like StageEndAlerts, predict the stage-end test from the last lesson of its last unit; no teacher order is needed.
                foreach ($lessonMaps[$class->id]['sessions'] ?? [] as $sessionId => $lesson) {
                    if ($lastLesson && $lesson->id === $lastLesson->id) {
                        $expected = $dates->get($sessionId)?->date;
                    }
                }
                $classTests = $tests->get($class->id, collect())
                    ->filter(fn ($test) => ! $assignment || ! $test->syllabus_stage_id || $test->syllabus_stage_id === $assignment->stage_id);
                $test = $classTests->first(fn ($test) => $test->scheduled_at->gte(today())) ?? $classTests->last();
                $observation = $observations->get($class->id);
                $enrolled = $class->roster_count;

                return [
                    'id' => $class->id, 'name' => $class->name, 'code' => $class->code,
                    'branch' => $class->branch?->name, 'program' => $class->program, 'level' => $class->level,
                    'teacher' => $class->teacher?->name,
                    'progress' => ['stage' => $assignment?->stage?->label ?? $assignment?->stage_name, 'unit' => $unit?->title],
                    'expected_big_test_date' => ($expected ?? $assignment?->expected_big_test_date)?->toDateString(),
                    'scheduled_big_test_date' => $test?->scheduled_at?->toDateString(),
                    'enrolled' => $enrolled,
                    'enrollment_badge' => $class->max_capacity > 0 && $enrolled >= $class->max_capacity ? 'Đủ sĩ số'
                        : ($enrolled < ($class->min_students ?? ClassModel::DEFAULT_MIN_STUDENTS) ? 'Cần tuyển' : null),
                    'observation' => $observation ? ['observed' => $observation->observed, 'observed_on' => $observation->observed_on?->toDateString(), 'month' => $observation->month] : null,
                    'students' => $students->get($class->id, collect())->map(fn ($student) => ['id' => $student->id, 'name' => $student->name, 'code' => $student->code])->values(),
                ];
            }),
        ]);
    }
}

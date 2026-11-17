<?php

namespace App\Services\Students;

use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Start due classes and move waiting students to studying, without requiring enrollment confirmation.
 * Also activate students immediately after manual confirmation or handoff into an already-started class.
 */
class ClassStartActivation
{
    public static function classHasStarted(?ClassModel $class): bool
    {
        return $class !== null && $class->status === 'active'
            && (! $class->start_date || $class->start_date->copy()->startOfDay()->lte(today()));
    }

    /** After manual enrollment completion, activate the student if the class has started. */
    public function forEnrollment(ClassEnrollment $enrollment): bool
    {
        $student = $enrollment->student;
        if (! $student || $student->status !== Student::INITIAL_STATUS || $enrollment->status !== 'completed'
            || ! self::classHasStarted($enrollment->classModel)) {
            return false;
        }

        $student->update(['status' => 'studying']);

        return true;
    }

    /** Waiting students with an active enrollment or current class that has started. */
    public function dueStudents(?CarbonInterface $now = null): Builder
    {
        $today = ($now ?? now())->toDateString();
        $startedClass = fn (Builder $class) => $class
            ->where('status', 'active')
            ->where(fn (Builder $date) => $date->whereNull('start_date')->orWhereDate('start_date', '<=', $today));

        return Student::query()
            ->where('status', Student::INITIAL_STATUS)
            ->where(fn (Builder $student) => $student
                ->whereHas('enrollments', fn (Builder $enrollment) => $enrollment
                    ->whereIn('status', Student::ACTIVE_ENROLLMENT_STATUSES)
                    ->whereHas('classModel', $startedClass))
                ->orWhereHas('currentClass', $startedClass));
    }

    /** @return array{classes_started: int, students_started: int} */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return DB::transaction(function () use ($now) {
            $counts = ['classes_started' => 0, 'students_started' => 0];
            ClassModel::query()
                ->where('status', 'upcoming')
                ->whereNotNull('start_date')
                ->whereDate('start_date', '<=', $now->toDateString())
                ->eachById(function (ClassModel $class) use (&$counts) {
                    $class->update(['status' => 'active']);
                    $counts['classes_started']++;
                });

            $this->dueStudents($now)->eachById(function (Student $student) use (&$counts) {
                $student->update(['status' => 'studying']);
                $counts['students_started']++;
            });

            return $counts;
        });
    }
}

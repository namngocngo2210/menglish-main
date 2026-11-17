<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\Student;
use App\Services\Students\ClassStartActivation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StartStudyingCommandTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 06:55:00'));
        $this->branch = Branch::create(['name' => 'Branch A', 'code' => 'CSA', 'is_active' => true]);
        $this->course = Course::create(['code' => 'FAM1', 'name' => 'Starters FAM 1', 'tuition_fee' => 10000000, 'total_lessons' => 48, 'is_active' => true]);
    }

    public function test_today_is_inclusive_but_tomorrow_does_not_start(): void
    {
        $today = $this->makeClass('TODAY', ['status' => 'upcoming', 'start_date' => today()]);
        $tomorrow = $this->makeClass('TOMORROW', ['status' => 'upcoming', 'start_date' => today()->addDay()]);
        $activeTomorrow = $this->makeClass('ACTIVE-TOMORROW', ['start_date' => today()->addDay()]);
        $student = $this->enroll($today, 'TODAY');
        $futureStudent = $this->enroll($tomorrow, 'TOMORROW');
        $activeFutureStudent = $this->enroll($activeTomorrow, 'ACTIVE-TOMORROW');

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 1 lớp và chuyển 1 học viên sang Đang học.')
            ->assertSuccessful();

        $this->assertSame('active', $today->fresh()->status);
        $this->assertSame('upcoming', $tomorrow->fresh()->status);
        $this->assertSame('studying', $student->fresh()->status);
        $this->assertSame('waiting_start', $futureStudent->fresh()->status);
        $this->assertSame('waiting_start', $activeFutureStudent->fresh()->status);
    }

    public function test_classes_without_a_due_start_date_or_with_other_statuses_are_untouched(): void
    {
        $classes = [
            $this->makeClass('NO-DATE', ['status' => 'upcoming']),
            $this->makeClass('PENDING', ['status' => 'pending_schedule', 'start_date' => today()->subDay()]),
            $this->makeClass('COMPLETED', ['status' => 'completed', 'start_date' => today()->subDay()]),
            $this->makeClass('CANCELLED', ['status' => 'cancelled', 'start_date' => today()->subDay()]),
        ];
        $students = [];
        foreach ($classes as $class) {
            $students[] = $this->enroll($class, $class->code);
        }

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 0 lớp và chuyển 0 học viên sang Đang học.')
            ->assertSuccessful();

        foreach ($classes as $class) {
            $this->assertSame($class->status, $class->fresh()->status);
        }
        foreach ($students as $student) {
            $this->assertSame('waiting_start', $student->fresh()->status);
        }
    }

    public function test_other_student_statuses_inactive_enrollments_and_deleted_students_are_untouched(): void
    {
        $class = $this->makeClass('ACTIVE', ['start_date' => today()->subDay()]);
        $students = [];
        foreach (['deferred', 'summer_break', 'studying', 'dropped', 'completed'] as $status) {
            $students[] = $this->enroll($class, $status, ['status' => $status, 'current_class_id' => $class->id]);
        }
        $dropped = $this->enroll($class, 'DROPPED-ENROLLMENT', [], 'dropped');
        $transferred = $this->enroll($class, 'TRANSFERRED-ENROLLMENT', [], 'transferred');
        $deleted = $this->enroll($class, 'DELETED');
        $deleted->delete();
        $unassigned = $this->makeStudent('UNASSIGNED');

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 0 lớp và chuyển 0 học viên sang Đang học.')
            ->assertSuccessful();

        foreach ($students as $student) {
            $this->assertSame($student->status, $student->fresh()->status);
        }
        foreach ([$dropped, $transferred, $unassigned] as $student) {
            $this->assertSame('waiting_start', $student->fresh()->status);
        }
        $this->assertSame('waiting_start', Student::withTrashed()->findOrFail($deleted->id)->status);
    }

    public function test_current_class_alone_starts_student_including_active_class_without_start_date(): void
    {
        $class = $this->makeClass('NO-DATE');
        $student = $this->makeStudent('CURRENT', ['current_class_id' => $class->id]);
        $futureClass = $this->makeClass('FUTURE', ['start_date' => today()->addDay()]);
        $futureStudent = $this->makeStudent('FUTURE', ['current_class_id' => $futureClass->id]);

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 0 lớp và chuyển 1 học viên sang Đang học.')
            ->assertSuccessful();

        $this->assertSame('studying', $student->fresh()->status);
        $this->assertSame('waiting_start', $futureStudent->fresh()->status);
        $this->assertSame(0, $student->enrollments()->count());
    }

    public function test_backfill_is_audited_and_rerun_changes_nothing(): void
    {
        $active = $this->makeClass('ACTIVE', ['start_date' => today()->subWeek()]);
        $upcoming = $this->makeClass('OVERDUE', ['status' => 'upcoming', 'start_date' => today()->subWeek()]);
        $pending = $this->enroll($active, 'PENDING');
        $confirmed = $this->enroll($upcoming, 'CONFIRMED', ['current_class_id' => $upcoming->id], 'completed');
        ClassEnrollment::create(['student_id' => $pending->id, 'class_id' => $upcoming->id, 'status' => 'pending']);

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 1 lớp và chuyển 2 học viên sang Đang học.')
            ->assertSuccessful();

        $this->assertSame('active', $upcoming->fresh()->status);
        $this->assertSame('studying', $pending->fresh()->status);
        $this->assertSame('studying', $confirmed->fresh()->status);
        foreach ([$upcoming, $pending, $confirmed] as $model) {
            $activity = Activity::forSubject($model)->where('event', 'updated')->latest('id')->firstOrFail();
            $this->assertSame($model->status, $activity->properties->get('old')['status']);
            $this->assertSame($model->fresh()->status, $activity->properties->get('attributes')['status']);
        }
        $auditCount = Activity::count();

        $this->artisan('students:start-studying')
            ->expectsOutput('Đã khai giảng 0 lớp và chuyển 0 học viên sang Đang học.')
            ->assertSuccessful();

        $this->assertSame($auditCount, Activity::count());
    }

    public function test_run_uses_injected_clock_without_mutating_it(): void
    {
        $class = $this->makeClass('INJECTED', ['status' => 'upcoming', 'start_date' => '2026-11-19']);
        $student = $this->enroll($class, 'INJECTED');
        $clock = CarbonImmutable::parse('2026-11-19 23:59:59');

        $counts = app(ClassStartActivation::class)->run($clock);

        $this->assertSame(['classes_started' => 1, 'students_started' => 1], $counts);
        $this->assertSame('active', $class->fresh()->status);
        $this->assertSame('studying', $student->fresh()->status);
        $this->assertSame('2026-11-19 23:59:59', $clock->format('Y-m-d H:i:s'));
    }

    private function makeClass(string $code, array $attributes = []): ClassModel
    {
        return ClassModel::create(array_merge([
            'code' => $code, 'name' => 'Class '.$code, 'course_id' => $this->course->id, 'branch_id' => $this->branch->id,
            'max_capacity' => 10, 'status' => 'active', 'tuition_fee' => 10000000,
        ], $attributes));
    }

    private function makeStudent(string $code, array $attributes = []): Student
    {
        return Student::create(array_merge([
            'code' => 'HV-'.$code, 'name' => 'Student '.$code, 'branch_id' => $this->branch->id,
            'status' => Student::INITIAL_STATUS, 'phone' => '0900000001',
        ], $attributes));
    }

    private function enroll(ClassModel $class, string $code, array $attributes = [], string $status = 'pending'): Student
    {
        $student = $this->makeStudent($code, $attributes);
        ClassEnrollment::create(['student_id' => $student->id, 'class_id' => $class->id, 'status' => $status]);

        return $student;
    }
}

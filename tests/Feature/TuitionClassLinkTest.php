<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Services\Tuition\TuitionClassLinker;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Gắn lớp học phí theo số buổi còn lại, giữ nguyên tiền và lịch sử khoản đã dùng hết. */
class TuitionClassLinkTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Course $course;

    private User $academic;

    private int $studentSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['code' => 'GL', 'name' => 'Cơ sở gắn lớp', 'is_active' => true]);
        $this->course = Course::create(['code' => 'GL24', 'name' => 'Khóa 24 buổi', 'total_lessons' => 24, 'tuition_fee' => 4800000, 'is_active' => true]);
        $this->academic = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->academic->assignRole('academic_staff');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_store_enrollment_links_only_unfinished_unassigned_tuitions(): void
    {
        $oldClass = $this->makeClass('CU');
        $placedClass = $this->makeClass('MOI');
        $otherClass = $this->makeClass('KHAC');
        $student = $this->makeStudent();
        $this->enroll($student, $oldClass, '2026-09-01', '2026-10-01', Student::ENROLLMENT_TRANSFERRED);
        $this->sessions($oldClass, ['2026-09-02']);
        $used = $this->tuition($student, null, 1);
        $unfinished = $this->tuition($student);
        // Trạng thái / tiền không quyết định khoản đã dùng hết hay còn buổi.
        $used->update(['status' => 'unpaid', 'paid_amount' => 0, 'debt_amount' => 4700000]);
        $unfinished->update(['status' => 'paid', 'paid_amount' => 4700000, 'debt_amount' => 0]);
        $other = $this->tuition($student, $otherClass);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), [
            'student_id' => $student->id, 'class_id' => $placedClass->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($used->fresh()->class_id);
        $this->assertSame($placedClass->id, $unfinished->fresh()->class_id);
        $this->assertSame($otherClass->id, $other->fresh()->class_id);
    }

    public function test_store_enrollment_applies_transfer_before_placement_when_main_class_changes(): void
    {
        $oldClass = $this->makeClass('CU');
        $newClass = $this->makeClass('MOI');
        $student = $this->makeStudent($oldClass);
        $this->sessions($oldClass, ['2026-09-02']);
        $this->sessions($newClass, ['2026-10-10']);
        $used = $this->tuition($student, $oldClass, 1);
        $unfinished = $this->tuition($student, $oldClass, 1);
        $unassigned = $this->tuition($student);

        $this->actingAs($this->academic)->post(route('students.enrollments.store'), [
            'student_id' => $student->id, 'class_id' => $newClass->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($oldClass->id, $used->fresh()->class_id);
        $this->assertSame($newClass->id, $unfinished->fresh()->class_id);
        $this->assertSame($newClass->id, $unassigned->fresh()->class_id);
    }

    public function test_backdated_transfer_uses_only_sessions_before_transfer_day_and_changes_only_class_id(): void
    {
        $oldClass = $this->makeClass('CU');
        $newClass = $this->makeClass('MOI');
        $otherClass = $this->makeClass('SONG-SONG');
        $student = $this->makeStudent($oldClass);
        $this->sessions($oldClass, ['2026-09-02', '2026-09-03', '2026-10-03']);
        $this->sessions($newClass, ['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06']);
        $used = $this->tuition($student, $oldClass, 2);
        $unfinished = $this->tuition($student, $oldClass, 2);
        $unassigned = $this->tuition($student, null, 1);
        $other = $this->tuition($student, $otherClass);
        StudentTuition::query()->toBase()->update(['updated_at' => '2026-09-30 12:00:00']);
        $before = collect([$used, $unfinished, $unassigned, $other])->mapWithKeys(
            fn (StudentTuition $tuition) => [$tuition->id => $tuition->fresh()->getRawOriginal()],
        );

        $this->actingAs($this->academic)->post(route('students.transfer-class', $student->id), [
            'class_id' => $newClass->id, 'transferred_on' => '2026-10-03',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($oldClass->id, $used->fresh()->class_id);
        $this->assertSame($newClass->id, $unfinished->fresh()->class_id);
        $this->assertSame($newClass->id, $unassigned->fresh()->class_id);
        $this->assertSame($otherClass->id, $other->fresh()->class_id);
        foreach ([$used, $unfinished, $unassigned, $other] as $tuition) {
            $this->assertSame(Arr::except($before[$tuition->id], ['class_id']), Arr::except($tuition->fresh()->getRawOriginal(), ['class_id']));
        }
    }

    public function test_link_class_without_main_class_links_tuition(): void
    {
        $student = $this->makeStudent();
        $class = $this->makeClass('MOI');
        $tuition = $this->tuition($student);

        $this->actingAs($this->academic)->post(route('students.link-class', $student->id), ['class_id' => $class->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($class->id, $student->fresh()->current_class_id);
        $this->assertSame($class->id, $tuition->fresh()->class_id);
    }

    public function test_parallel_placement_links_unassigned_tuition_to_main_class(): void
    {
        $mainClass = $this->makeClass('CHINH');
        $linkedClass = $this->makeClass('LIEN-KET');
        $student = $this->makeStudent($mainClass);
        $tuition = $this->tuition($student);

        $this->actingAs($this->academic)->post(route('students.link-class', $student->id), ['class_id' => $linkedClass->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($mainClass->id, $tuition->fresh()->class_id);
        $this->assertSame($mainClass->id, $student->fresh()->current_class_id);
    }

    public function test_linker_falls_back_to_placed_class_and_returns_only_changed_ids(): void
    {
        $student = $this->makeStudent();
        $class = $this->makeClass('MOI');
        $tuition = $this->tuition($student);
        $linker = app(TuitionClassLinker::class);
        $before = $tuition->fresh()->getRawOriginal();

        $plan = $linker->planUnassigned($student, $class);
        $this->assertSame([$tuition->id], array_column($plan, 'tuition_id'));
        $this->assertSame($before, $tuition->fresh()->getRawOriginal());
        $this->assertSame([$tuition->id], $linker->linkUnassigned($student, $class));
        $this->assertSame([], $linker->linkUnassigned($student, $class));
        $this->assertSame(Arr::except($before, ['class_id']), Arr::except($tuition->fresh()->getRawOriginal(), ['class_id']));
    }

    public function test_backfill_dry_run_real_run_and_second_run_share_the_same_plan(): void
    {
        $oldClass = $this->makeClass('CU');
        $mainClass = $this->makeClass('CHINH');
        $parallelClass = $this->makeClass('SONG-SONG');
        $student = $this->makeStudent($mainClass, '2026-10-01');
        $this->enroll($student, $oldClass, '2026-09-01', '2026-10-01', Student::ENROLLMENT_TRANSFERRED);
        $this->enroll($student, $parallelClass, '2026-09-01');
        $this->sessions($oldClass, ['2026-09-02']);
        $used = $this->tuition($student, null, 1);
        $used->forceFill(['created_at' => '2026-09-01 20:00:00'])->saveQuietly();
        $unassigned = $this->tuition($student);
        $transferred = $this->tuition($student, $oldClass);
        $parallel = $this->tuition($student, $parallelClass);
        $usedLinked = $this->tuition($this->makeStudent($oldClass), $oldClass, 1);
        $this->enroll($usedLinked->student, $mainClass, '2026-10-01');
        $usedLinked->student->leaveMainClass('2026-10-01');
        $usedLinked->student->update(['current_class_id' => $mainClass->id]);
        $droppedStudent = $this->makeStudent();
        $droppedStudent->update(['status' => Student::STATUS_DROPPED]);
        $dropped = $this->tuition($droppedStudent);
        $before = StudentTuition::orderBy('id')->get()->mapWithKeys(fn ($tuition) => [$tuition->id => $tuition->getRawOriginal()])->all();

        $this->artisan('tuitions:link-classes', ['--dry-run' => true])
            ->expectsOutputToContain($student->code)
            ->expectsOutputToContain('Dự kiến thay đổi: 3')
            ->assertSuccessful();
        $this->assertSame($before, StudentTuition::orderBy('id')->get()->mapWithKeys(fn ($tuition) => [$tuition->id => $tuition->getRawOriginal()])->all());

        $this->artisan('tuitions:link-classes')->expectsOutputToContain('Đã thay đổi: 3')->assertSuccessful();

        $this->assertSame($oldClass->id, $used->fresh()->class_id);
        $this->assertSame($mainClass->id, $unassigned->fresh()->class_id);
        $this->assertSame($mainClass->id, $transferred->fresh()->class_id);
        $this->assertSame($parallelClass->id, $parallel->fresh()->class_id);
        $this->assertSame($oldClass->id, $usedLinked->fresh()->class_id);
        $this->assertNull($dropped->fresh()->class_id);
        foreach (StudentTuition::all() as $tuition) {
            $this->assertSame(Arr::except($before[$tuition->id], ['class_id']), Arr::except($tuition->getRawOriginal(), ['class_id']));
        }

        $this->artisan('tuitions:link-classes')->expectsOutputToContain('Dự kiến thay đổi: 0. Đã thay đổi: 0.')->assertSuccessful();
    }

    public function test_backfill_skips_unknown_history_and_prefers_latest_main_enrollment_on_creation_date(): void
    {
        $oldClass = $this->makeClass('CU');
        $mainClass = $this->makeClass('CHINH');
        $parallelClass = $this->makeClass('SONG-SONG');
        $student = $this->makeStudent($mainClass, '2026-10-01');
        $this->enroll($student, $oldClass, '2026-09-01', '2026-10-01', Student::ENROLLMENT_TRANSFERRED);
        $this->enroll($student, $parallelClass, '2026-09-20');
        $this->sessions($oldClass, ['2026-09-02', '2026-09-03', '2026-09-04']);
        $unknown = $this->tuition($student, null, 1);
        $unknown->forceFill(['created_at' => '2026-08-01 10:00:00'])->saveQuietly();
        $historical = $this->tuition($student, null, 1);
        $historical->forceFill(['created_at' => '2026-09-25 10:00:00'])->saveQuietly();
        $boundary = $this->tuition($student, null, 1);
        $boundary->forceFill(['created_at' => '2026-10-01 10:00:00'])->saveQuietly();

        $this->artisan('tuitions:link-classes')->expectsOutputToContain('Bỏ qua: 1')->assertSuccessful();

        $this->assertNull($unknown->fresh()->class_id);
        $this->assertSame($oldClass->id, $historical->fresh()->class_id);
        $this->assertSame($mainClass->id, $boundary->fresh()->class_id);
    }

    public function test_backfill_keeps_unfinished_tuition_of_a_class_with_an_active_enrollment(): void
    {
        $oldClass = $this->makeClass('CU');
        $mainClass = $this->makeClass('CHINH');
        $student = $this->makeStudent($mainClass);
        $this->enroll($student, $oldClass, '2026-08-01', '2026-09-01', Student::ENROLLMENT_TRANSFERRED);
        $this->enroll($student, $oldClass, '2026-09-05');
        $tuition = $this->tuition($student, $oldClass);

        $this->artisan('tuitions:link-classes')->expectsOutputToContain('Dự kiến thay đổi: 0')->assertSuccessful();

        $this->assertSame($oldClass->id, $tuition->fresh()->class_id);
    }

    private function makeClass(string $code): ClassModel
    {
        return ClassModel::create([
            'code' => $code, 'name' => 'Lớp '.$code, 'branch_id' => $this->branch->id,
            'course_id' => $this->course->id, 'status' => 'active', 'max_capacity' => 20, 'start_date' => '2026-08-01',
        ]);
    }

    private function makeStudent(?ClassModel $class = null, string $enrolledAt = '2026-09-01'): Student
    {
        $student = Student::create([
            'code' => 'HV-GL-'.++$this->studentSequence, 'name' => 'Học viên gắn lớp', 'phone' => '0900000001',
            'branch_id' => $this->branch->id, 'current_class_id' => $class?->id, 'status' => 'studying',
        ]);
        if ($class) {
            $this->enroll($student, $class, $enrolledAt);
        }

        return $student;
    }

    private function enroll(Student $student, ClassModel $class, string $enrolledAt, ?string $leftAt = null, string $status = 'completed'): void
    {
        ClassEnrollment::create([
            'student_id' => $student->id, 'class_id' => $class->id, 'enrolled_at' => $enrolledAt,
            'left_at' => $leftAt, 'status' => $status,
        ]);
    }

    private function sessions(ClassModel $class, array $dates): void
    {
        foreach ($dates as $date) {
            ClassSession::create([
                'class_id' => $class->id, 'branch_id' => $class->branch_id, 'date' => $date,
                'start_time' => '08:00', 'end_time' => '09:30', 'status' => 'scheduled',
            ]);
        }
    }

    private function tuition(Student $student, ?ClassModel $class = null, int $sessions = 24): StudentTuition
    {
        return StudentTuition::create([
            'student_id' => $student->id, 'class_id' => $class?->id, 'branch_id' => $this->branch->id,
            'total_amount' => 4800000, 'final_amount' => 4700000, 'discount_amount' => 200000, 'other_fees' => 100000,
            'paid_amount' => 1200000, 'debt_amount' => 3500000, 'status' => 'partial', 'debt_status' => 'T+3',
            'session_count' => $sessions, 'transfer_memo' => 'Nội dung đã chốt',
        ]);
    }
}

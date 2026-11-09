<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherPortalController;
use App\Models\AcademicRecord;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\ClassSessionFeedback;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

class StudentPortalHistoryTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $parent;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(today()->setTime(12, 0));
        $this->buildSyllabusWorkflow();
        $this->parent = $this->userWithRole(Roles::STUDENT, 'Parent A');
        $this->student = $this->studentFor($this->parent, 'HISTORY-A');
    }

    public function test_absent_big_test_shows_makeup_notice_until_score_is_approved(): void
    {
        $absentTest = BigTest::create(['code' => 'BTA-1', 'title' => 'BT absent', 'class_id' => $this->class->id, 'test_type' => 'stage_end', 'scheduled_at' => today(), 'room' => 'Lab', 'passcode' => 'PA1', 'status' => 'draft']);
        $normalTest = BigTest::create(['code' => 'BTA-2', 'title' => 'BT normal', 'class_id' => $this->class->id, 'test_type' => 'stage_end', 'scheduled_at' => today(), 'room' => 'Lab', 'passcode' => 'PA2', 'status' => 'draft']);
        $absent = BigTestResult::create(['big_test_id' => $absentTest->id, 'student_id' => $this->student->id,
            'is_absent' => true, 'status' => 'approved', 'approved_at' => now()]);
        BigTestResult::create(['big_test_id' => $normalTest->id, 'student_id' => $this->student->id,
            'overall_score' => 7, 'listening_score' => 7, 'status' => 'sent', 'approved_at' => now()->subDay()]);
        $url = route('portal.student.homework', $this->student->id);

        $this->actingAs($this->parent)->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('bigTestResults', 2)
                ->where('bigTestResults.0.is_absent', true)
                ->where('bigTestResults.0.makeup_notice', BigTestResult::MAKEUP_NOTICE)
                ->where('bigTestResults.0.components', [])
                ->where('bigTestResults.1.makeup_notice', null));

        // Make-up score approved & sent: result shown, no notice.
        $absent->update(['is_absent' => false, 'overall_score' => 8, 'listening_score' => 8, 'status' => 'sent']);
        $this->actingAs($this->parent)->get($url)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('bigTestResults.0.is_absent', false)
                ->where('bigTestResults.0.makeup_notice', null)
                ->has('bigTestResults.0.components', 1));
    }

    public function test_mini_test_details_support_both_skill_formats_and_are_private(): void
    {
        $legacy = MiniTestScore::create(['student_id' => $this->student->id, 'class_id' => $this->class->id,
            'user_id' => $this->teacher->id, 'name' => 'Legacy test', 'test_date' => today()->subDay(),
            'score' => 8, 'max_score' => 10, 'note' => 'Teacher comment',
            'skill_scores' => ['listening' => 8.5, 'speaking' => 0, 'reading' => null, 'writing' => '']]);
        $modern = MiniTestScore::create(['student_id' => $this->student->id, 'class_id' => $this->class->id,
            'user_id' => $this->teacher->id, 'name' => 'New test', 'test_date' => today(), 'score' => 9, 'max_score' => 10,
            'skill_scores' => [['name' => 'Vocabulary', 'score' => 9.50], ['name' => 'Fluency', 'score' => 0],
                ['name' => 'Missing', 'score' => null], ['name' => 'Blank', 'score' => ' '], ['name' => ' ', 'score' => 8]]]);
        $otherParent = $this->userWithRole(Roles::STUDENT, 'Parent B');
        $other = $this->studentFor($otherParent, 'HISTORY-B');
        MiniTestScore::create(['student_id' => $other->id, 'class_id' => $this->class->id, 'user_id' => $this->teacher->id,
            'name' => 'Private B test', 'test_date' => today(), 'score' => 10, 'max_score' => 10]);

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('miniTests', 2)
                ->where('miniTests.0.id', $modern->id)
                ->where('miniTests.0.skills', [['name' => 'Vocabulary', 'score' => '9.5'], ['name' => 'Fluency', 'score' => '0']])
                ->where('miniTests.1.id', $legacy->id)->where('miniTests.1.note', 'Teacher comment')
                ->where('miniTests.1.skills', [['name' => 'Nghe', 'score' => '8.5'], ['name' => 'Nói', 'score' => '0']]));
        $this->actingAs($otherParent)->get(route('portal.student.homework', $this->student->id))
            ->assertForbidden()->assertDontSee('Legacy test')->assertDontSee('Teacher comment');
        $this->actingAs($otherParent)->get(route('portal.student.homework', $other->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('miniTests', 1)->where('miniTests.0.name', 'Private B test'));
    }

    public function test_only_recent_taught_sessions_without_homework_are_empty(): void
    {
        $past = $this->classSess(-2);
        $assigned = $this->classSess(-1);
        $this->homework($assigned);
        $started = $this->classSess(0, ['status' => 'scheduled', 'start_time' => '08:00']);
        $completed = $this->classSess(0, ['status' => 'completed', 'start_time' => '18:00']);
        $this->classSess(0, ['status' => 'scheduled', 'start_time' => '19:00']);
        $this->classSess(1);
        $this->classSess(-3, ['status' => 'cancelled']);
        $this->classSess(-4, ['type' => ClassSession::TYPE_SUPPORT]);
        $this->classSess(-31);
        $inactive = ClassModel::create(['code' => 'UNRELATED', 'name' => 'Unrelated class', 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->classSess(-1, ['class_id' => $inactive->id]);

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('emptySessions', fn ($rows) => collect($rows)->pluck('id')->all() === [$completed->id, $started->id, $past->id])
                ->where('emptySessions.2.class_name', $this->class->name)
                ->where('emptySessions.2.date', $past->date->toDateString())->where('emptySessions.2.label', 'Buổi 2'));
    }

    public function test_transfer_history_is_readonly_and_preserves_submissions_without_filtering_active_join_date(): void
    {
        $oldClass = ClassModel::create(['code' => 'OLD', 'name' => 'Old class', 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->student->enrollments()->create(['class_id' => $oldClass->id, 'status' => Student::ENROLLMENT_TRANSFERRED,
            'enrolled_at' => today()->subDays(80), 'left_at' => today()->subDays(40)]);
        $this->student->enrollments()->create(['class_id' => $this->class->id, 'status' => 'completed', 'enrolled_at' => today()->subDay()]);
        $this->assertEqualsCanonicalizing([$oldClass->id, $this->class->id], $this->student->historyClassIds());
        $old = $this->classSess(-50, ['class_id' => $oldClass->id]);
        $this->remark($old, [$this->student->id => ['comment' => 'Old teacher comment']]);
        $hw = $this->homework($old);
        $submission = HomeworkSubmission::create(['homework_id' => $hw->id, 'student_id' => $this->student->id, 'basket' => 'quizizz',
            'status' => 'submitted', 'submitted_at' => now()->subDays(49), 'feedback' => 'Old homework feedback']);
        ClassSessionFeedback::create(['class_session_id' => $old->id, 'class_id' => $oldClass->id, 'student_id' => $this->student->id,
            'source' => ClassSessionFeedback::SOURCE_PARENT, 'author_id' => $this->parent->id, 'rating' => 4]);
        $active = $this->classSess(-3);
        $activeHw = $this->homework($active);
        // Submitted homework remains visible even when assigned after leaving.
        $after = $this->classSess(-20, ['class_id' => $oldClass->id]);
        $afterHw = $this->homework($after);
        HomeworkSubmission::create(['homework_id' => $afterHw->id, 'student_id' => $this->student->id, 'basket' => 'quizizz',
            'status' => 'submitted', 'submitted_at' => now()]);
        $excludedHw = $this->homework($this->classSess(-10, ['class_id' => $oldClass->id]));
        $this->remark($after, [$this->student->id => ['comment' => 'Outside enrollment']]);
        $before = $this->classSess(-81, ['class_id' => $oldClass->id]);
        $this->remark($before, [$this->student->id => ['comment' => 'Before enrollment']]);
        $draft = $this->classSess(-60, ['class_id' => $oldClass->id]);
        $this->remark($draft, [$this->student->id => ['comment' => 'Draft comment']], 'draft');
        $otherParent = $this->userWithRole(Roles::STUDENT, 'Parent B');
        $other = $this->studentFor($otherParent, 'HISTORY-B');
        HomeworkSubmission::create(['homework_id' => $excludedHw->id, 'student_id' => $other->id, 'basket' => 'quizizz',
            'status' => 'submitted', 'submitted_at' => now()]);
        $otherOnly = $this->classSess(-55, ['class_id' => $oldClass->id]);
        $this->remark($otherOnly, [$other->id => ['comment' => 'Private B remark']]);
        HomeworkSubmission::create(['homework_id' => $activeHw->id, 'student_id' => $other->id, 'basket' => 'quizizz',
            'status' => 'submitted', 'submitted_at' => now(), 'feedback' => 'Private B feedback']);

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('remarks', 2)
                ->where('remarks.0.session_id', $active->id)->where('remarks.0.readonly', false)->where('remarks.0.can_rate', true)
                ->where('remarks.1.session_id', $old->id)->where('remarks.1.class_name', 'Old class')->where('remarks.1.readonly', true)
                ->where('remarks.1.remark.comment', 'Old teacher comment')->where('remarks.1.can_rate', false)->where('remarks.1.feedback.editable', false)
                ->where('assignments', fn ($rows) => collect($rows)->pluck('id')->all() === [$activeHw->id, $afterHw->id, $hw->id])
                ->where('assignments.0.readonly', false)->where('assignments.0.baskets.0.submission', null)
                ->where('assignments.2.readonly', true)->where('assignments.2.class_name', 'Old class')
                ->where('assignments.2.baskets.0.submission.id', $submission->id)->where('assignments.2.baskets.0.submission.feedback', 'Old homework feedback')
                ->where('sessionProgress.'.$old->id, ['submitted' => 1, 'total' => 1])
                ->where('basketProgress', ['submitted' => 2, 'total' => 3]));
        $this->actingAs($this->parent)->post(route('portal.student.homework.basket', [$hw->id, 'quizizz']), ['student_id' => $this->student->id])
            ->assertNotFound();
        $this->assertSame('Old homework feedback', $submission->fresh()->feedback);
        $this->actingAs($otherParent)->get(route('portal.student.homework', $this->student->id))->assertForbidden();
        $this->actingAs($otherParent)->get(route('portal.student.homework', $other->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('remarks', fn ($rows) => ! collect($rows)->contains('session_id', $old->id))
                ->where('assignments', fn ($rows) => collect($rows)->pluck('id')->all() === [$activeHw->id]));
    }

    public function test_history_limits_and_unattached_homework_leaving_day_cutoff(): void
    {
        $oldClass = ClassModel::create(['code' => 'OLD', 'name' => 'Old class', 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->student->enrollments()->create(['class_id' => $oldClass->id, 'status' => Student::ENROLLMENT_TRANSFERRED,
            'enrolled_at' => null, 'left_at' => today()->subDays(40)]);
        $oldIds = [];
        for ($i = 40; $i < 52; $i++) {
            $session = $this->classSess(-$i, ['class_id' => $oldClass->id]);
            $this->remark($session, [$this->student->id => ['comment' => 'Published history']]);
            $oldIds[] = $session->id;
        }
        $activeIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $activeIds[] = $this->classSess(-$i)->id;
        }
        $boundary = Homework::create(['class_id' => $oldClass->id, 'user_id' => $this->teacher->id,
            'title' => 'Leaving day homework', 'items' => ['quiz' => 'Practice']]);
        $boundary->forceFill(['created_at' => today()->subDays(40)->endOfDay()])->save();
        $later = Homework::create(['class_id' => $oldClass->id, 'user_id' => $this->teacher->id,
            'title' => 'After leaving', 'items' => ['quiz' => 'Practice']]);
        $later->forceFill(['created_at' => today()->subDays(39)])->save();

        $this->actingAs($this->parent)->get(route('portal.student.homework', $this->student->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('remarks', fn ($rows) => collect($rows)->pluck('session_id')->all() === [...array_slice($activeIds, 0, 5), ...array_slice($oldIds, 0, 10)])
                ->has('assignments', 1)->where('assignments.0.id', $boundary->id)->where('assignments.0.readonly', true)
                ->where('sessionProgress.none', ['submitted' => 0, 'total' => 1]));
    }

    private function studentFor(User $parent, string $code): Student
    {
        return Student::create(['name' => $code, 'code' => $code, 'phone' => '0930000001', 'status' => 'studying',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'user_id' => $parent->id]);
    }

    private function classSess(int $offset, array $attributes = []): ClassSession
    {
        return ClassSession::create($attributes + ['class_id' => $this->class->id, 'branch_id' => $this->branch->id,
            'date' => today()->addDays($offset), 'start_time' => '08:00', 'end_time' => '09:30',
            'type' => ClassSession::TYPE_REGULAR, 'status' => 'completed', 'teacher_id' => $this->teacher->id]);
    }

    private function homework(ClassSession $session): Homework
    {
        return Homework::create(['class_id' => $session->class_id, 'class_session_id' => $session->id, 'user_id' => $this->teacher->id,
            'title' => 'Session homework', 'items' => ['quiz' => 'Practice quiz']]);
    }

    private function remark(ClassSession $session, array $data, string $status = 'completed'): void
    {
        AcademicRecord::create(['module' => 'teacher_remarks', 'screen_key' => 'teacher_remarks',
            'record_code' => TeacherPortalController::remarkRecordCode($session), 'title' => 'Session remark',
            'user_id' => $this->teacher->id, 'status' => $status, 'data' => $data]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\BigTest;
use App\Models\ClassLessonOverride;
use App\Models\ClassLessonProgress;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusLesson;
use App\Services\SessionLessonService;
use App\Services\Syllabus\SyllabusOrders;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/** Lỗi nhỏ để sau của giai đoạn 3–4 (docs/kho-syllabus.md §8, §9): mỗi test tái hiện một lỗi. */
class SyllabusDeferredMinorsTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private SyllabusAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyllabusWorkflow();
        $this->assignment = $this->openStage($this->stage1, ['start_date' => today()->subDays(7), 'opened_at' => today()->subDays(7), 'confirmed_at' => now()]);
    }

    public function test_big_test_score_link_opens_the_class_stage_test(): void
    {
        $this->lesson(1)->update(['lesson_type' => 'big_test']);
        $this->addSession(0, '18:00');
        $mine = $this->bigTest('BT-MINE', $this->class->id, $this->stage1->id);
        $other = ClassModel::create(['code' => 'QT-OTHER', 'name' => 'Lớp khác', 'branch_id' => $this->branch->id, 'status' => 'active', 'teacher_id' => $this->teacher->id]);
        $this->bigTest('BT-NEWER', $other->id, $this->stage1->id);

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.score_url', route('syllabus.big-tests.results', $mine->id))->etc());
    }

    public function test_second_session_on_the_same_day_can_be_marked(): void
    {
        $morning = $this->addSession(0, '08:00');
        $evening = $this->addSession(0, '18:00');
        $url = route('teacher.lesson-today.taught', $this->class->id);
        $this->actingAs($this->teacher)->post($url, ['class_session_id' => $morning->id])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('session.id', $evening->id)
                ->where('lesson.session_no', 2)
                ->where('can_mark', true)
                ->etc());
        $this->actingAs($this->teacher)->post($url, ['class_session_id' => $evening->id])->assertSessionHasNoErrors();
        $this->assertSame([1, 2], ClassLessonProgress::with('lesson')->orderBy('id')->get()->map(fn ($p) => $p->lesson->session_no)->all());
    }

    public function test_substitute_teacher_can_mark_own_past_session(): void
    {
        $substitute = $this->userWithRole(Roles::TEACHER_FULLTIME, 'GV dạy thay');
        $past = $this->addSession(-1, '18:00', $substitute->id);
        $this->addSession(0, '18:00', $substitute->id);

        $this->actingAs($substitute)->get(route('teacher.lesson-today', ['class' => $this->class->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unmarked.0.id', $past->id)->where('unmarked.0.can_mark', true)->etc());
        $this->actingAs($substitute)->post(route('teacher.lesson-today.taught', $this->class->id), ['class_session_id' => $past->id])->assertSessionHasNoErrors();
    }

    public function test_timetable_label_uses_class_override_title(): void
    {
        $session = $this->addSession(1, '18:00');
        ClassLessonOverride::create(['class_id' => $this->class->id, 'syllabus_lesson_id' => $this->lesson(1)->id, 'data' => ['title' => 'Bài 1 (rút gọn)'],
            'reason' => 'Lớp yếu', 'edited_by' => $this->lead->id]);

        $label = app(SessionLessonService::class)->lessonsFor(collect([$session]))[$session->id];

        $this->assertSame('Bài 1 (rút gọn)', $label['title']);
        $this->assertStringContainsString('Bài: Bài 1 (rút gọn)', $label['summary']);
    }

    public function test_order_milestone_without_planned_sessions_is_still_due(): void
    {
        $this->lesson(1)->update(['notice_milestone' => 'order_print']);

        $milestone = app(SyllabusOrders::class)->milestones($this->assignment)->sole();

        $this->assertTrue($milestone['due'], 'TKB chưa có buổi: mốc tại buổi hiện tại vẫn đến hạn.');
        $this->assertNull($milestone['use_date']);
        $this->assertNull($milestone['due_date']);
    }

    public function test_test_order_deadline_falls_back_to_a_week_before_exam(): void
    {
        $this->lesson(1)->update(['notice_milestone' => 'order_big_test']);
        $this->lesson(2)->update(['lesson_type' => 'big_test']);
        $this->addSession(1, '18:00');
        $this->addSession(3, '18:00');

        $milestone = app(SyllabusOrders::class)->milestones($this->assignment)->sole();

        $this->assertTrue($milestone['due']);
        $this->assertSame(today()->addDays(3)->toDateString(), $milestone['exam_date']->toDateString());
        $this->assertSame(today()->subDays(4)->toDateString(), $milestone['due_date']->toDateString(), 'Chưa đủ 3 buổi trước buổi test: hạn = 7 ngày trước buổi test.');
    }

    private function lesson(int $no): SyllabusLesson
    {
        return SyllabusLesson::where('curriculum_id', $this->curriculum->id)->where('session_no', $no)->firstOrFail();
    }

    private function addSession(int $days, string $start, ?int $teacherId = null): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => today()->addDays($days)->toDateString(),
            'start_time' => $start, 'end_time' => date('H:i', strtotime($start) + 5400), 'type' => ClassSession::TYPE_REGULAR, 'status' => 'scheduled',
            'teacher_id' => $teacherId ?? $this->teacher->id]);
    }

    private function bigTest(string $code, int $classId, int $stageId): BigTest
    {
        return BigTest::create(['code' => $code, 'title' => 'Big Test '.$code, 'class_id' => $classId, 'syllabus_stage_id' => $stageId, 'test_type' => 'big',
            'scheduled_at' => now()->addDay(), 'room' => 'Lab', 'passcode' => 'PASS-'.$code, 'is_distributed' => true, 'status' => 'distributed']);
    }
}

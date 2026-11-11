<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\SyllabusLesson;
use App\Models\SyllabusLessonSlide;
use App\Models\User;
use App\Services\CoverService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/**
 * TK-2026-0057 (owner 10/11): an approved cover teacher sees data of the approved session only — never other sessions of
 * the class, class-wide screens, or other lessons. Permission matrix: before approval / approved / sibling session /
 * class-wide / main teacher / admin + Học vụ / after the session.
 */
class CoverPerSessionScopeTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $cover;

    private ClassSession $session;

    private ClassSession $sibling;

    private SyllabusLessonSlide $slide;

    private SyllabusLessonSlide $siblingSlide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(10, 0));
        $this->buildSyllabusWorkflow();
        $this->openStage($this->stage1);
        $this->cover = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV dạy thay');
        Student::create(['code' => 'CV-ST', 'name' => 'HS lớp', 'phone' => '0901234567', 'branch_id' => $this->branch->id,
            'current_class_id' => $this->class->id, 'status' => 'studying']);
        $this->session = $this->makeSession('2026-12-11');
        $this->sibling = $this->makeSession('2026-12-18');
        $this->slide = $this->slideForLesson(1);
        $this->siblingSlide = $this->slideForLesson(2);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function makeSession(string $date): ClassSession
    {
        return ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => '18:00', 'end_time' => '19:30', 'shift_name' => 'Ca tối', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
    }

    private function slideForLesson(int $sessionNo): SyllabusLessonSlide
    {
        return SyllabusLessonSlide::forceCreate(['syllabus_lesson_id' => SyllabusLesson::where('session_no', $sessionNo)->firstOrFail()->id,
            'kind' => 'ppt', 'file_path' => "x{$sessionNo}.pptx", 'original_name' => "x{$sessionNo}.pptx"]);
    }

    private function approveCover(): void
    {
        $request = app(CoverService::class)->create($this->session, $this->teacher, 'Bận');
        app(CoverService::class)->register($request, $this->cover);
        app(CoverService::class)->approve($request, $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ duyệt'), $this->cover->id);
    }

    /** @return list<int> ids of the sessions listed on the class page "Buổi học" tab */
    private function listedSessions(User $user): array
    {
        $response = $this->actingAs($user)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'sessions']))->assertOk();

        return collect($response->viewData('page')['props']['sessions'])->pluck('id')->sort()->values()->all();
    }

    public function test_cover_before_approval_reaches_nothing(): void
    {
        $this->actingAs($this->cover)->get(route('classes.show', $this->class->id))->assertNotFound();
        $this->assertFalse($this->slide->isVisibleTo($this->cover));
        $this->assertFalse(Student::visibleTo($this->cover)->where('code', 'CV-ST')->exists());
        $this->actingAs($this->cover)->get(route('classes.session-report', [$this->class->id, $this->session->id]))->assertForbidden();
    }

    public function test_approved_cover_sees_only_the_approved_session(): void
    {
        $this->approveCover();
        $this->travelTo(now()->setDate(2026, 12, 11)->setTime(9, 0));

        // Approved session: class page, roster, lesson slide.
        $this->actingAs($this->cover)->get(route('classes.show', $this->class->id))->assertOk();
        $this->assertTrue(Student::visibleTo($this->cover)->where('code', 'CV-ST')->exists());
        $this->assertTrue($this->slide->isVisibleTo($this->cover));
        $this->assertSame([$this->session->id], $this->listedSessions($this->cover));

        // Sibling session of the same class: not listed, its lesson material and report are denied.
        $this->assertFalse($this->siblingSlide->isVisibleTo($this->cover));
        $this->travelTo(now()->setDate(2026, 12, 19)->setTime(9, 0));
        $this->actingAs($this->cover)->get(route('classes.session-report', [$this->class->id, $this->sibling->id]))->assertForbidden();
    }

    public function test_class_wide_screens_and_scopes_stay_closed_for_cover(): void
    {
        $this->approveCover();

        $this->actingAs($this->cover)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'academic']))->assertForbidden();
        // Default scope (used by every class-wide report / dashboard / task / KPI screen) never includes cover sessions.
        $this->assertFalse(ClassModel::visibleTo($this->cover)->whereKey($this->class->id)->exists());
        $this->assertFalse(ClassModel::visibleTo($this->cover)->taughtBy($this->cover)->whereKey($this->class->id)->exists());
        $this->actingAs($this->cover)->get(route('classes.index', ['mine' => 1]))->assertOk();
    }

    public function test_main_teacher_admin_and_hoc_vu_are_unaffected(): void
    {
        $this->approveCover();
        $admin = $this->userWithRole(Roles::ADMIN, 'Admin');
        $staff = $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ');

        // The class teacher keeps the whole class (all sessions, academic tab) even for the covered session.
        $this->assertSame([$this->sibling->id], ClassSession::forStaff($this->teacher->id)->pluck('id')->all());
        $this->assertTrue(ClassModel::visibleTo($this->teacher)->whereKey($this->class->id)->exists());
        $this->assertSame([$this->session->id, $this->sibling->id], $this->listedSessions($staff));
        $this->assertSame([$this->session->id, $this->sibling->id], $this->listedSessions($admin));
        $this->actingAs($this->teacher)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'academic']))->assertOk();
        $this->actingAs($admin)->get(route('classes.show', ['id' => $this->class->id, 'tab' => 'academic']))->assertOk();
        $this->assertTrue($this->siblingSlide->isVisibleTo($this->teacher));
        $this->assertTrue($this->siblingSlide->isVisibleTo($staff));
    }

    public function test_access_follows_the_approval_and_is_unchanged_after_the_session_ends(): void
    {
        $this->approveCover();
        $this->travelTo(now()->setDate(2026, 12, 12)->setTime(9, 0));

        // Session finished: the class page / roster stay as before this change, limited to the approved session; lesson
        // material follows the syllabus timeline, so it never widens to the sibling session's lesson.
        $this->assertSame([$this->session->id], $this->listedSessions($this->cover));
        $this->assertFalse($this->siblingSlide->isVisibleTo($this->cover));

        // Cancelling the approval returns the session to the class teacher and closes everything at once.
        $request = $this->session->coverRequests()->firstOrFail();
        app(CoverService::class)->cancel($request, $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ hủy'));
        $this->actingAs($this->cover)->get(route('classes.show', $this->class->id))->assertNotFound();
    }
}

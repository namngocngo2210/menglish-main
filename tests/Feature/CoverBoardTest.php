<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** TK-2026-0057 PR2: màn Dạy thay + thông báo. */
class CoverBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $other;
    private User $approver;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(10, 0));
        $this->teacher = $this->staff(Roles::TEACHER_FULLTIME);
        $this->other = $this->staff(Roles::TEACHER_PARTTIME);
        $this->approver = $this->staff(Roles::ACADEMIC_STAFF);
        $branch = Branch::create(['name' => 'Board branch', 'code' => 'BOARD', 'is_active' => true]);
        $course = Course::create(['code' => 'BOARD', 'name' => 'Board course', 'tuition_fee' => 0, 'total_lessons' => 12, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'BOARD', 'name' => 'Secret class', 'course_id' => $course->id,
            'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
        $this->session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id, 'date' => '2026-12-11',
            'start_time' => '18:00', 'end_time' => '19:30', 'shift_name' => 'Ca tối', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_teaching_team_sees_board_but_assistant_and_student_do_not(): void
    {
        $this->actingAs($this->staff(Roles::ASSISTANT))->get(route('cover.board'))->assertForbidden();
        $this->actingAs($this->staff(Roles::STUDENT))->get(route('cover.board'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('cover.board'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Cover/Index')->where('canApprove', false)
                ->has('sessions', 1)->where('sessions.0.id', $this->session->id)->has('covers', 0));
    }

    public function test_open_request_is_listed_for_other_teachers_without_class_info_and_session_leaves_my_list(): void
    {
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertCreated();
        $this->actingAs($this->other)->get(route('cover.board'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('covers', 1)->missing('covers.0.class')->missing('covers.0.students')->has('sessions', 0));
        $this->actingAs($this->teacher)->get(route('cover.board'))->assertInertia(fn (Assert $page) => $page->has('sessions', 0)->has('covers', 1));
    }

    public function test_approver_board_lists_open_and_approved_requests_with_registrations(): void
    {
        $id = $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->json('id');
        $this->actingAs($this->other)->postJson(route('teacher.cover.register', $id))->assertOk();
        $this->actingAs($this->approver)->postJson(route('cover-requests.approve', $id), ['teacher_id' => $this->other->id])->assertOk();
        $this->actingAs($this->approver)->get(route('cover.board'))
            ->assertInertia(fn (Assert $page) => $page->where('canApprove', true)->has('covers', 1)
                ->where('covers.0.status', 'approved')->has('covers.0.registrations', 1)->where('covers.0.class.name', 'Secret class'));
    }

    public function test_notifications_follow_the_cover_flow_without_leaking_the_class_name(): void
    {
        $id = $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->json('id');
        $opened = AdminNotification::where('type', 'cover_opened')->get();
        $this->assertTrue($opened->pluck('user_id')->contains($this->other->id));
        $this->assertFalse($opened->pluck('user_id')->contains($this->teacher->id));
        $this->assertStringNotContainsString('Secret', $opened->pluck('title')->implode(' '));

        $this->actingAs($this->other)->postJson(route('teacher.cover.register', $id))->assertOk();
        $this->assertTrue(AdminNotification::where('type', 'cover_registered')->pluck('user_id')->contains($this->approver->id));

        $this->actingAs($this->approver)->postJson(route('cover-requests.approve', $id), ['teacher_id' => $this->other->id])->assertOk();
        $this->assertTrue(AdminNotification::where('type', 'cover_approved')->pluck('user_id')->contains($this->other->id));
        $this->assertTrue(AdminNotification::where('type', 'cover_approved')->pluck('user_id')->contains($this->teacher->id));

        $this->actingAs($this->approver)->postJson(route('cover-requests.reopen', $id))->assertOk();
        $this->assertTrue(AdminNotification::where('type', 'cover_reopened')->where('user_id', $this->other->id)->exists());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}

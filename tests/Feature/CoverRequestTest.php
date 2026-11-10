<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CoverRequest;
use App\Models\Student;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private User $cover;
    private User $approver;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(10, 0));
        $this->teacher = $this->staff(Roles::TEACHER_FULLTIME);
        $this->cover = $this->staff(Roles::TEACHER_PARTTIME);
        $this->approver = $this->staff(Roles::ACADEMIC_STAFF);
        $branch = Branch::create(['name' => 'Cover branch', 'code' => 'COVER', 'is_active' => true]);
        $course = Course::create(['code' => 'COVER', 'name' => 'Cover course', 'tuition_fee' => 0, 'total_lessons' => 12, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'COVER', 'name' => 'Private class', 'course_id' => $course->id,
            'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active']);
        Student::create(['code' => 'COVER-ST', 'name' => 'Private student', 'phone' => '0901234567',
            'branch_id' => $branch->id, 'current_class_id' => $class->id, 'status' => 'studying']);
        $this->session = ClassSession::create(['class_id' => $class->id, 'branch_id' => $branch->id,
            'date' => '2026-12-11', 'start_time' => '18:00', 'end_time' => '19:30', 'shift_name' => 'Ca tối',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_creation_requires_own_session_and_request_permission_or_approval_permission(): void
    {
        $this->actingAs($this->cover)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertForbidden();
        // Removing a role grant exercises the permission check independently of ownership.
        $this->teacher->roles->first()->revokePermissionTo('cover.request');
        $this->teacher = $this->teacher->fresh();
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertForbidden();
        $this->actingAs($this->approver)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertCreated();
    }

    public function test_main_and_foreign_teachers_can_request_their_sessions(): void
    {
        $request = $this->createRequest();
        $this->assertSame($this->teacher->id, $request->requested_by);
        $this->assertSame($this->teacher->id, $request->original_teacher_id);
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.cancel', $request))->assertOk();
        $foreign = $this->staff(Roles::FOREIGN_TEACHER);
        $this->session->update(['foreign_teacher_id' => $foreign->id]);
        $this->actingAs($foreign)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id, 'reason' => 'Bận việc'])
            ->assertCreated()->assertJsonPath('reason', 'Bận việc');
    }

    public function test_only_one_active_request_is_allowed_including_approved_requests(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertUnprocessable();
        $this->register($request);
        $this->approve($request);
        $this->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertUnprocessable();
    }

    public function test_creation_rejects_past_started_and_unscheduled_sessions(): void
    {
        foreach ([['date' => '2026-12-09'], ['date' => '2026-12-10', 'start_time' => '10:00'], ['date' => '2026-12-11', 'status' => 'cancelled']] as $changes) {
            $this->session->update($changes);
            $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cover_requests', 0);
    }

    public function test_registration_requires_teaching_permission_and_excludes_session_teachers(): void
    {
        $request = $this->createRequest();
        foreach ([Roles::ASSISTANT, Roles::STUDENT] as $role) {
            $this->actingAs($this->staff($role))->postJson(route('teacher.cover.register', $request))->assertForbidden();
        }
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
        $foreign = $this->staff(Roles::FOREIGN_TEACHER);
        $this->session->update(['foreign_teacher_id' => $foreign->id]);
        $this->actingAs($foreign)->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
        $this->register($request);
        $this->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
        $this->assertDatabaseCount('cover_registrations', 1);
    }

    public function test_original_and_current_teachers_cannot_register_after_session_assignment_changes(): void
    {
        $request = $this->createRequest();
        $this->session->update(['teacher_id' => $this->cover->id]);
        foreach ([$this->teacher, $this->cover] as $teacher) {
            $this->actingAs($teacher)->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
        }
    }

    public function test_nullable_original_teacher_is_restored_on_reopen_and_cancel(): void
    {
        $this->session->update(['teacher_id' => null]);
        $response = $this->actingAs($this->approver)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id]);
        $response->assertCreated();
        $request = CoverRequest::findOrFail($response->json('id'));
        $this->register($request);
        $this->approve($request);
        $this->postJson(route('cover-requests.reopen', $request))->assertOk();
        $this->assertNull($this->session->fresh()->teacher_id);
        $this->approve($request);
        $this->postJson(route('teacher.cover.cancel', $request))->assertOk();
        $this->assertNull($this->session->fresh()->teacher_id);
    }

    public function test_unregister_only_removes_own_registration_while_registration_is_open(): void
    {
        $request = $this->createRequest();
        $other = $this->staff(Roles::FOREIGN_TEACHER);
        $this->register($request, $other);
        $this->register($request);
        $this->deleteJson(route('teacher.cover.unregister', $request))->assertOk()->assertJsonPath('i_registered', false);
        $this->assertDatabaseHas('cover_registrations', ['cover_request_id' => $request->id, 'teacher_id' => $other->id]);
        $this->register($request);
        $this->approve($request);
        $this->actingAs($this->cover)->deleteJson(route('teacher.cover.unregister', $request))->assertUnprocessable();
    }

    public function test_index_access_and_privacy_before_and_after_approval(): void
    {
        $request = $this->createRequest();
        foreach ([Roles::ASSISTANT, Roles::STUDENT] as $role) {
            $this->actingAs($this->staff($role))->getJson(route('teacher.cover.index'))->assertForbidden();
        }
        $this->actingAs($this->cover)->getJson(route('teacher.cover.index'))->assertOk()
            ->assertJsonPath('data.0.session.id', $this->session->id)
            ->assertJsonMissingPath('data.0.class')->assertJsonMissingPath('data.0.students')
            ->assertJsonMissingPath('data.0.registrations')->assertDontSee('Private class')->assertDontSee('Private student');
        $this->getJson(route('teacher.cover.index', ['status' => 'approved']))->assertForbidden();
        $this->actingAs($this->teacher)->getJson(route('teacher.cover.index'))->assertJsonPath('data.0.class.name', 'Private class');
        $this->register($request);
        $this->getJson(route('teacher.cover.index'))->assertJsonPath('data.0.i_registered', true)->assertJsonPath('data.0.registrations_count', 1);
        $this->actingAs($this->approver)->getJson(route('teacher.cover.index'))->assertJsonPath('data.0.registrations.0.teacher_id', $this->cover->id)
            ->assertJsonPath('data.0.students.0.name', 'Private student');
        $this->approve($request);
        $this->actingAs($this->cover)->getJson(route('teacher.cover.index'))->assertJsonPath('data.0.class.name', 'Private class')
            ->assertJsonPath('data.0.students.0.name', 'Private student');
        $this->actingAs($this->staff(Roles::FOREIGN_TEACHER))->getJson(route('teacher.cover.index'))->assertJsonCount(0, 'data');
        $this->actingAs($this->approver)->getJson(route('teacher.cover.index', ['status' => 'approved']))->assertJsonCount(1, 'data');
        $this->getJson(route('teacher.cover.index', ['status' => 'invalid']))->assertUnprocessable();
    }

    public function test_approval_requires_approver_and_a_registrant_and_closes_registration(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->teacher)->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->cover->id])->assertForbidden();
        $this->actingAs($this->approver)->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->cover->id])->assertUnprocessable();
        $this->register($request);
        $this->approve($request);
        $this->assertSame($this->cover->id, $this->session->fresh()->teacher_id);
        $this->assertSame($this->teacher->id, $request->fresh()->original_teacher_id);
        $this->assertSame($this->approver->id, $request->fresh()->approved_by);
        $this->assertNotNull($request->fresh()->approved_at);
        $this->actingAs($this->staff(Roles::FOREIGN_TEACHER))->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
    }

    public function test_admin_can_approve_via_gate(): void
    {
        $request = $this->createRequest();
        $this->register($request);
        $this->approve($request, $this->staff(Roles::ADMIN));
        $this->assertSame($this->cover->id, $this->session->fresh()->teacher_id);
    }

    public function test_reopen_restores_teacher_clears_approval_and_keeps_registrants(): void
    {
        $request = $this->createRequest();
        $this->register($request);
        $this->approve($request);
        $this->actingAs($this->cover)->postJson(route('cover-requests.reopen', $request))->assertForbidden();
        $this->actingAs($this->approver)->postJson(route('cover-requests.reopen', $request))->assertOk()->assertJsonPath('status', 'open');
        $this->assertSame($this->teacher->id, $this->session->fresh()->teacher_id);
        foreach (['approved_user_id', 'approved_by', 'approved_at'] as $field) {
            $this->assertNull($request->fresh()->{$field});
        }
        $this->assertSame(1, $request->registrations()->count());
        $this->actingAs($this->cover)->getJson(route('teacher.cover.index'))->assertJsonMissingPath('data.0.class')
            ->assertJsonMissingPath('data.0.students');
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
        $this->register($request, $this->staff(Roles::FOREIGN_TEACHER));
        $this->approve($request);
        $this->assertSame($this->teacher->id, $request->fresh()->original_teacher_id);
    }

    public function test_reassignment_preserves_original_teacher_and_rejects_nonregistrants_atomically(): void
    {
        $request = $this->createRequest();
        $other = $this->staff(Roles::FOREIGN_TEACHER);
        $this->register($request);
        $this->register($request, $other);
        $this->approve($request);
        $this->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->teacher->id])->assertUnprocessable();
        $this->assertSame($this->cover->id, $this->session->fresh()->teacher_id);
        $this->postJson(route('cover-requests.approve', $request), ['teacher_id' => $other->id])->assertOk();
        $this->assertSame($other->id, $this->session->fresh()->teacher_id);
        $this->assertSame($this->teacher->id, $request->fresh()->original_teacher_id);
    }

    public function test_cancel_requires_requester_or_approver_and_restores_approved_teacher(): void
    {
        $request = $this->createRequest();
        $this->actingAs($this->cover)->postJson(route('teacher.cover.cancel', $request))->assertForbidden();
        $this->register($request);
        $this->approve($request);
        $this->actingAs($this->teacher)->postJson(route('teacher.cover.cancel', $request))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame($this->teacher->id, $this->session->fresh()->teacher_id);
        $this->assertNotNull($request->fresh()->cancelled_at);
        $this->postJson(route('teacher.cover.cancel', $request))->assertUnprocessable();
        $this->actingAs($this->approver)->postJson(route('cover-requests.reopen', $request))->assertUnprocessable();
        $this->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->cover->id])->assertUnprocessable();
        $this->registerClosed($request);
        $new = $this->createRequest();
        $this->actingAs($this->approver)->postJson(route('teacher.cover.cancel', $new))->assertOk();
        $this->assertSame($this->teacher->id, $this->session->fresh()->teacher_id);
    }

    public function test_registration_unregistration_and_approval_close_at_session_start(): void
    {
        $request = $this->createRequest();
        $this->register($request);
        $this->travelTo($this->session->startsAt());
        $this->registerClosed($request);
        $this->actingAs($this->cover)->deleteJson(route('teacher.cover.unregister', $request))->assertUnprocessable();
        $this->actingAs($this->approver)->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->cover->id])->assertUnprocessable();
        $this->assertSame($this->teacher->id, $this->session->fresh()->teacher_id);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function createRequest(): CoverRequest
    {
        $response = $this->actingAs($this->teacher)->postJson(route('teacher.cover.store'), ['class_session_id' => $this->session->id]);
        $response->assertCreated();

        return CoverRequest::findOrFail($response->json('id'));
    }

    private function register(CoverRequest $request, ?User $teacher = null): void
    {
        $this->actingAs($teacher ?? $this->cover)->postJson(route('teacher.cover.register', $request))->assertOk();
    }

    private function approve(CoverRequest $request, ?User $approver = null): void
    {
        $this->actingAs($approver ?? $this->approver)->postJson(route('cover-requests.approve', $request), ['teacher_id' => $this->cover->id])
            ->assertOk()->assertJsonPath('status', 'approved');
    }

    private function registerClosed(CoverRequest $request): void
    {
        $this->actingAs($this->staff(Roles::FOREIGN_TEACHER))->postJson(route('teacher.cover.register', $request))->assertUnprocessable();
    }
}

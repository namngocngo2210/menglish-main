<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\CoverRequest;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SyllabusLesson;
use App\Models\SyllabusLessonSlide;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\CoverService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyllabusWorkflow;
use Tests\TestCase;

/**
 * TK-2026-0057 PR3: a cover teacher gets class / student / material access only once approved, and loses it when the
 * request is reopened or cancelled. Access rides on class_sessions.teacher_id (set by CoverService), so every scope that
 * already honours session staff (ClassModel::visibleTo, Student::visibleTo, slides, attendance) is covered here.
 */
class CoverScopeTest extends TestCase
{
    use BuildsSyllabusWorkflow, RefreshDatabase;

    private User $cover;

    private User $other;

    private ClassSession $session;

    private SyllabusLessonSlide $slide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 12, 10)->setTime(10, 0));
        $this->buildSyllabusWorkflow();
        $this->openStage($this->stage1);
        $this->cover = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV dạy thay');
        $this->other = $this->userWithRole(Roles::TEACHER_PARTTIME, 'GV khác');
        Student::create(['code' => 'CV-ST', 'name' => 'HS riêng', 'phone' => '0901234567', 'branch_id' => $this->branch->id,
            'current_class_id' => $this->class->id, 'status' => 'studying']);
        $this->session = ClassSession::create(['class_id' => $this->class->id, 'branch_id' => $this->branch->id,
            'date' => '2026-12-11', 'start_time' => '18:00', 'end_time' => '19:30', 'shift_name' => 'Ca tối',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled']);
        $lesson = SyllabusLesson::where('session_no', 1)->firstOrFail();
        $this->slide = SyllabusLessonSlide::forceCreate(['syllabus_lesson_id' => $lesson->id, 'kind' => 'ppt',
            'file_path' => 'x.pptx', 'original_name' => 'x.pptx']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string, bool> what $user can reach for the class */
    private function reach(User $user): array
    {
        return [
            'class' => ClassModel::visibleTo($user, true, true)->whereKey($this->class->id)->exists(),
            'student' => Student::visibleTo($user)->where('code', 'CV-ST')->exists(),
            'slide' => $this->slide->isVisibleTo($user),
        ];
    }

    private function openAndRegister(): CoverRequest
    {
        $request = app(CoverService::class)->create($this->session, $this->teacher, 'Bận');
        app(CoverService::class)->register($request, $this->cover);

        return $request;
    }

    public function test_access_matrix_denied_before_approval_allowed_after_and_denied_again_on_reopen_or_cancel(): void
    {
        $none = ['class' => false, 'student' => false, 'slide' => false];
        $all = ['class' => true, 'student' => true, 'slide' => true];

        $this->assertSame($none, $this->reach($this->cover));
        $request = $this->openAndRegister();
        // Registered but not approved: still nothing, and unrelated teachers never gain access.
        $this->assertSame($none, $this->reach($this->cover));
        $this->assertSame($none, $this->reach($this->other));
        $this->actingAs($this->cover)->get(route('teacher.attendance', [$this->class->id, 'session' => $this->session->id]))->assertForbidden();

        $approver = $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ duyệt');
        app(CoverService::class)->approve($request, $approver, $this->cover->id);
        $this->assertSame($all, $this->reach($this->cover));
        $this->assertSame($none, $this->reach($this->other));
        $this->actingAs($this->cover)->get(route('teacher.attendance', [$this->class->id, 'session' => $this->session->id]))->assertOk();

        app(CoverService::class)->reopen($request, $approver);
        $this->assertSame($none, $this->reach($this->cover));

        app(CoverService::class)->approve($request->fresh(), $approver, $this->cover->id);
        app(CoverService::class)->cancel($request->fresh(), $approver);
        $this->assertSame($none, $this->reach($this->cover));
        $this->assertSame($this->teacher->id, $this->session->fresh()->teacher_id);
    }

    public function test_approval_does_not_open_other_classes_or_other_sessions_of_the_class_for_stage_documents_of_other_classes(): void
    {
        $otherClass = ClassModel::create(['code' => 'OTH', 'name' => 'Lớp khác', 'branch_id' => $this->branch->id,
            'status' => 'active', 'teacher_id' => $this->teacher->id]);
        $request = $this->openAndRegister();
        app(CoverService::class)->approve($request, $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ duyệt'), $this->cover->id);

        $this->assertFalse(ClassModel::visibleTo($this->cover, true, true)->whereKey($otherClass->id)->exists());
        // Cover never becomes the class teacher: "Lớp của tôi" stays empty, only the session is theirs.
        $this->assertFalse(ClassModel::visibleTo($this->cover)->taughtBy($this->cover)->whereKey($this->class->id)->exists());
        $this->assertSame($this->teacher->id, $this->class->fresh()->teacher_id);
        $this->assertSame([$this->session->id], ClassSession::forStaff($this->cover->id)->pluck('id')->all());
    }

    public function test_cover_teacher_checks_in_and_takes_attendance_under_own_name(): void
    {
        $request = $this->openAndRegister();
        app(CoverService::class)->approve($request, $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ duyệt'), $this->cover->id);
        $this->travelTo(now()->setDate(2026, 12, 11)->setTime(18, 5));
        $student = Student::where('code', 'CV-ST')->firstOrFail();

        // The original teacher no longer owns the session's check-in.
        $this->actingAs($this->cover)->post(route('teacher.checkin'), ['session_ids' => [$this->session->id]]);
        $timesheet = TeacherTimesheet::where('class_session_id', $this->session->id)->first();
        $this->assertNotNull($timesheet);
        $this->assertSame($this->cover->id, $timesheet->user_id);

        $this->actingAs($this->cover)->post(route('teacher.attendance.store', $this->class->id), [
            'class_session_id' => $this->session->id, 'status' => [$student->id => 'present'],
        ])->assertSessionHasNoErrors();
        $attendance = StudentAttendance::where('class_session_id', $this->session->id)->firstOrFail();
        $this->assertSame($this->cover->id, $attendance->user_id);
    }

    public function test_unfilled_open_request_reminds_attendance_staff_once_when_session_starts(): void
    {
        $staff = $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ');
        $request = app(CoverService::class)->create($this->session, $this->teacher);
        $this->artisan('cover:remind-unfilled')->assertSuccessful();
        $this->assertSame(0, AdminNotification::where('type', 'cover_unfilled')->count(), 'before the session starts');

        $this->travelTo(now()->setDate(2026, 12, 11)->setTime(18, 1));
        $this->artisan('cover:remind-unfilled')->assertSuccessful();
        $this->artisan('cover:remind-unfilled')->assertSuccessful();
        $this->assertSame(1, AdminNotification::where('type', 'cover_unfilled')->where('user_id', $staff->id)->count());
        $this->assertSame(0, AdminNotification::where('type', 'cover_unfilled')->whereIn('user_id', [$this->teacher->id, $this->cover->id])->count());
        $this->assertNotNull($request->fresh()->attendance_reminded_at);
    }

    public function test_approved_or_cancelled_requests_are_not_reminded(): void
    {
        $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ');
        $request = $this->openAndRegister();
        app(CoverService::class)->approve($request, $this->userWithRole(Roles::ACADEMIC_STAFF, 'Học vụ duyệt'), $this->cover->id);
        $this->travelTo(now()->setDate(2026, 12, 11)->setTime(18, 1));
        $this->artisan('cover:remind-unfilled')->assertSuccessful();
        $this->assertSame(0, AdminNotification::where('type', 'cover_unfilled')->count());
    }
}

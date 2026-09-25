<?php

namespace Tests\Feature;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassSession;
use App\Models\CrmCustomer;
use App\Models\MiniTestScore;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SupportSession;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\SupportListService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 vòng 2: điểm danh theo buổi, danh sách lớp thật (roster), danh sách bổ trợ tự động,
 * cổng học viên, chăm sóc tháng đầu, sinh nhật, chấm bài nộp.
 */
class Phase2AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $teacher;

    private User $academicStaff;

    private User $manager;

    private ClassModel $classModel;

    private ClassModel $otherClass;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Thứ 4
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-P2A', 'is_active' => true]);
        $this->teacher = $this->makeUser('teacher');
        $this->academicStaff = $this->makeUser('academic_staff');
        $this->manager = $this->makeUser('manager');

        $this->classModel = ClassModel::create([
            'name' => 'Lớp Điểm Danh', 'code' => 'P2A-01', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'room' => 'P101', 'status' => 'active', 'max_capacity' => 10,
        ]);
        $this->otherClass = ClassModel::create([
            'name' => 'Lớp Khác', 'code' => 'P2A-02', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->makeUser('teacher')->id, 'room' => 'P102', 'status' => 'active',
        ]);
        $this->student = $this->makeStudent('Học viên Chính', $this->classModel);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Điểm danh theo buổi ────────────────────────────────────────────

    public function test_attendance_is_keyed_by_session_and_allows_makeup_for_past_session(): void
    {
        $past = $this->makeSession('2026-10-05');
        $today = $this->makeSession('2026-10-07', '08:00', '09:30');
        $makeup = $this->makeSession('2026-10-07', '14:00', '15:30', ClassSession::TYPE_MAKEUP);

        $this->actingAs($this->teacher)->get(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $past->id]))
            ->assertOk()->assertSee('Điểm danh bù')->assertSee($this->student->name);

        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $past->id, 'status' => [$this->student->id => 'present'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'status' => [$this->student->id => 'late'], // mặc định: buổi chính khóa hôm nay
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $makeup->id, 'status' => [$this->student->id => 'present'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, StudentAttendance::count());
        $this->assertDatabaseHas('student_attendances', ['class_session_id' => $past->id, 'session_date' => '2026-10-05 00:00:00', 'status' => 'present']);
        $this->assertDatabaseHas('student_attendances', ['class_session_id' => $today->id, 'status' => 'late']);
        $this->assertDatabaseHas('student_attendances', ['class_session_id' => $makeup->id, 'status' => 'present']);
        $this->assertSame('completed', $past->fresh()->status);
        $this->assertSame('completed', $makeup->fresh()->status);

        // Lưu lại cùng buổi không tạo bản ghi mới.
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $past->id, 'status' => [$this->student->id => 'excused'], 'note' => [$this->student->id => 'Ốm'],
        ]);
        $this->assertSame(3, StudentAttendance::count());
    }

    public function test_cancelled_future_and_foreign_sessions_are_rejected(): void
    {
        $cancelled = $this->makeSession('2026-10-06', status: 'cancelled');
        $future = $this->makeSession('2026-10-09');
        $foreign = ClassSession::create([
            'class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-07',
            'start_time' => '08:00', 'end_time' => '09:00', 'status' => 'scheduled',
        ]);

        foreach ([$cancelled, $future] as $session) {
            $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
                'class_session_id' => $session->id, 'status' => [$this->student->id => 'present'],
            ])->assertSessionHasErrors('session');
        }
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $foreign->id, 'status' => [$this->student->id => 'present'],
        ])->assertNotFound();
        // Không có buổi hôm nay: báo lỗi thay vì lưu điểm danh không gắn buổi.
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'status' => [$this->student->id => 'present'],
        ])->assertSessionHasErrors('session');

        $this->assertSame(0, StudentAttendance::count());
    }

    public function test_academic_staff_records_on_behalf_of_teacher_but_manager_of_other_branch_cannot(): void
    {
        $session = $this->makeSession('2026-10-07');

        $this->actingAs($this->academicStaff)->get(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $session->id]))
            ->assertOk()->assertSee('Điểm danh thay giáo viên');
        $this->actingAs($this->academicStaff)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$this->student->id => 'present'],
        ])->assertSessionHasNoErrors();

        $row = StudentAttendance::sole();
        $this->assertSame($this->teacher->id, (int) $row->user_id);
        $this->assertSame($this->academicStaff->id, (int) $row->recorded_by);

        $otherBranch = Branch::create(['name' => 'Hà Đông', 'code' => 'HD-P2A', 'is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true, 'branch_id' => $otherBranch->id]);
        $outsider->assignRole('manager');
        $this->actingAs($outsider)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$this->student->id => 'absent'],
        ])->assertForbidden();

        $this->actingAs($this->manager)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$this->student->id => 'late'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('late', $row->fresh()->status);
    }

    // ── 2. Lịch dạy của giáo viên ─────────────────────────────────────────

    public function test_teacher_home_shows_today_week_and_pending_sessions_from_class_sessions(): void
    {
        $today = $this->makeSession('2026-10-07', '08:00', '09:30');
        $this->makeSession('2026-10-09', '18:00', '19:30');
        $this->makeSession('2026-10-02', '18:00', '19:30'); // tuần trước, chưa điểm danh
        $assistantClass = ClassModel::create([
            'name' => 'Lớp Trợ Giảng', 'code' => 'P2A-TA', 'branch_id' => $this->branch->id,
            'teacher_id' => $this->otherClass->teacher_id, 'status' => 'active',
        ]);
        ClassSession::create([
            'class_id' => $assistantClass->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-08',
            'start_time' => '17:00', 'end_time' => '18:00', 'teacher_id' => $this->otherClass->teacher_id,
            'assistant_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);

        $this->actingAs($this->teacher)->get(route('teacher.home'))
            ->assertOk()
            ->assertSee('08:00 - 09:30')
            ->assertSee('Lớp Trợ Giảng')
            ->assertSee('18:00-19:30')
            ->assertSee('Điểm danh bù')
            ->assertSee(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $today->id]), false);

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$today->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('teacher_timesheets', ['user_id' => $this->teacher->id, 'class_session_id' => $today->id]);
    }

    // ── 3. Danh sách lớp thật ─────────────────────────────────────────────

    public function test_roster_unions_linked_students_and_excludes_left_students(): void
    {
        $linked = $this->makeStudent('Học viên Liên Kết', $this->otherClass);
        ClassEnrollment::create(['student_id' => $linked->id, 'class_id' => $this->classModel->id, 'status' => 'pending']);
        $this->makeStudent('Học viên Bảo Lưu', $this->classModel, 'deferred');
        $dropped = $this->makeStudent('Học viên Thôi Học', $this->otherClass);
        ClassEnrollment::create(['student_id' => $dropped->id, 'class_id' => $this->classModel->id, 'status' => 'pending']);
        $dropped->update(['status' => 'dropped']);

        $names = $this->classModel->rosterStudents()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Học viên Chính', 'Học viên Liên Kết'], $names);
        $this->assertSame(2, $this->classModel->occupiedSeats());

        $session = $this->makeSession('2026-10-07');
        $this->actingAs($this->teacher)->get(route('teacher.attendance', ['classId' => $this->classModel->id, 'session' => $session->id]))
            ->assertSee('Học viên Liên Kết')->assertDontSee('Học viên Bảo Lưu')->assertDontSee('Học viên Thôi Học');
        $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$linked->id => 'present'],
        ])->assertSessionHasNoErrors();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('classes.profile', $this->classModel->id))
            ->assertOk()->assertSee('Học viên Liên Kết')->assertDontSee('Học viên Bảo Lưu')->assertSee('2 học sinh');
    }

    // ── 4. Danh sách bổ trợ ───────────────────────────────────────────────

    public function test_absence_and_low_scores_feed_the_support_list_idempotently(): void
    {
        $session = $this->makeSession('2026-10-07');
        $post = fn (string $status) => $this->actingAs($this->teacher)->post(route('teacher.attendance.store', $this->classModel->id), [
            'class_session_id' => $session->id, 'status' => [$this->student->id => $status], 'note' => [$this->student->id => 'Lý do vắng'],
        ]);

        $post('absent');
        $post('absent');
        $this->assertSame(1, ClassReportStudentSupport::where('source', SupportListService::SOURCE_ATTENDANCE)->count());
        $post('present');
        $this->assertSame(0, ClassReportStudentSupport::where('source', SupportListService::SOURCE_ATTENDANCE)->count());

        MiniTestScore::create(['class_id' => $this->classModel->id, 'student_id' => $this->student->id, 'name' => 'Mini 1',
            'score' => 12, 'max_score' => 20, 'test_date' => '2026-10-06']);
        MiniTestScore::create(['class_id' => $this->classModel->id, 'student_id' => $this->student->id, 'name' => 'Mini 2',
            'score' => 9, 'max_score' => 10, 'test_date' => '2026-10-06']);
        $low = ClassReportStudentSupport::where('source', SupportListService::SOURCE_MINI_TEST)->sole();
        $this->assertEquals(6.0, (float) $low->score);

        $test = BigTest::create(['code' => 'BT-P2A', 'title' => 'Big Test giữa kỳ', 'class_id' => $this->classModel->id,
            'test_type' => 'midterm', 'scheduled_at' => now(), 'is_distributed' => true, 'status' => 'approved']);
        $absentee = $this->makeStudent('Vắng Thi', $this->classModel);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $this->student->id, 'overall_score' => 5.5, 'status' => 'pending_review']);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $absentee->id, 'is_absent' => true, 'status' => 'pending_review']);
        $this->assertSame(1, ClassReportStudentSupport::where('source', SupportListService::SOURCE_BIG_TEST)->count());

        $this->actingAs($this->academicStaff)->get(route('tasks.support-sessions'))
            ->assertOk()->assertSee('Mini test &lt; 7', false)->assertSee('Big Test &lt; 7', false)
            ->assertSee('Big Test "Big Test giữa kỳ": 5.5/10');
    }

    public function test_support_scheduling_ignores_cancelled_sessions_and_checks_assistant_role(): void
    {
        $assistant = $this->makeUser('assistant');
        $item = ClassReportStudentSupport::create(['class_id' => $this->classModel->id, 'student_id' => $this->student->id,
            'source' => SupportListService::SOURCE_ATTENDANCE, 'source_id' => 999, 'reason' => 'Vắng học buổi 05/10']);
        ClassSession::create(['class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-08',
            'start_time' => '09:00', 'end_time' => '10:00', 'assistant_id' => $assistant->id, 'status' => 'scheduled']);
        ClassSession::create(['class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-08',
            'start_time' => '14:00', 'end_time' => '15:00', 'teacher_id' => $assistant->id, 'room' => 'P301', 'status' => 'cancelled']);

        $payload = ['class_report_student_support_id' => $item->id, 'class_id' => $this->classModel->id, 'student_id' => $this->student->id,
            'teacher_id' => $assistant->id, 'session_date' => '2026-10-08'];

        $this->actingAs($this->manager)->post(route('tasks.support-sessions.store'), $payload + ['start_time' => '09:30', 'end_time' => '10:30'])
            ->assertSessionHasErrors('start_time');
        // Buổi liền kề không bị coi là trùng; buổi đã hủy không chặn.
        $this->actingAs($this->manager)->post(route('tasks.support-sessions.store'), $payload + ['start_time' => '14:00', 'end_time' => '15:00', 'room' => 'P301'])
            ->assertSessionHasNoErrors();

        $support = SupportSession::sole();
        $this->assertSame($item->id, (int) $support->class_report_student_support_id);
        $this->assertSame('Vắng học buổi 05/10', $support->reason);
        $this->actingAs($this->manager)->get(route('tasks.support-sessions'))->assertSee('Không có học viên nào đang chờ xếp buổi bổ trợ.');
    }

    // ── 5. Cổng học viên ──────────────────────────────────────────────────

    public function test_student_portal_shows_own_schedule_attendance_and_released_big_tests(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->student->update(['user_id' => $user->id]);
        ClassEnrollment::create(['student_id' => $this->student->id, 'class_id' => $this->otherClass->id, 'status' => 'pending']);
        ClassSession::create(['class_id' => $this->otherClass->id, 'branch_id' => $this->branch->id, 'date' => '2026-10-10',
            'start_time' => '09:00', 'end_time' => '10:30', 'room' => 'P777', 'status' => 'scheduled']);
        $past = $this->makeSession('2026-10-05');
        StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $past->id, 'student_id' => $this->student->id,
            'session_date' => '2026-10-05', 'status' => 'absent']);
        $test = BigTest::create(['code' => 'BT-P2P', 'title' => 'Big Test Đã Duyệt', 'class_id' => $this->classModel->id,
            'test_type' => 'midterm', 'scheduled_at' => now(), 'is_distributed' => true, 'status' => 'approved']);
        BigTestResult::create(['big_test_id' => $test->id, 'student_id' => $this->student->id, 'overall_score' => 8.5, 'status' => 'approved', 'approved_at' => now()]);
        $draft = BigTest::create(['code' => 'BT-P2D', 'title' => 'Big Test Nháp', 'class_id' => $this->classModel->id,
            'test_type' => 'final', 'scheduled_at' => now(), 'is_distributed' => true, 'status' => 'approved']);
        BigTestResult::create(['big_test_id' => $draft->id, 'student_id' => $this->student->id, 'overall_score' => 9, 'status' => 'pending_review']);

        $this->actingAs($user)->get(route('portal.student.home'))
            ->assertOk()
            ->assertSee('Lịch học sắp tới')->assertSee('Phòng P777')
            ->assertSee('05/10/2026')->assertSee('Vắng')
            ->assertSee('Big Test Đã Duyệt')->assertDontSee('Big Test Nháp')
            ->assertDontSee('Cô Huyền')->assertDontSee('123 Đường ABC');

        $this->actingAs($user)->get(route('portal.student.homework'))
            ->assertOk()->assertDontSee('Unit 4: Present Continuous')->assertDontSee('Mini Test 1')->assertSee('Chưa có nhận xét buổi học nào.');
    }

    public function test_teacher_submissions_show_only_real_submissions_of_the_selected_class(): void
    {
        $outsider = $this->makeStudent('Ngoài Lớp', $this->otherClass);
        foreach ([[$this->student, 'Bài của lớp'], [$outsider, 'Bài lớp khác']] as [$student, $note]) {
            AcademicRecord::create(['screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap', 'module' => 'student_portal',
                'record_code' => 'SUB-'.$student->id, 'title' => 'Bài nộp', 'status' => 'submitted',
                'data' => ['student_id' => (string) $student->id, 'student_name' => $student->name, 'homework_type' => 'video', 'notes' => $note]]);
        }

        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', ['classId' => $this->classModel->id]))
            ->assertOk()->assertSee('Bài của lớp')->assertDontSee('Bài lớp khác')
            ->assertDontSee('Nguyễn Văn An')->assertDontSee('Vũ Thị Minh Hằng')->assertDontSee('Trần Hồng Sơn')
            ->assertSee('Danh sách đã nộp bài (1)');
        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', ['classId' => $this->classModel->id, 'type' => 'workbook']))
            ->assertSee('Danh sách đã nộp bài (0)');
        $this->actingAs($this->teacher)->get(route('portal.teacher.submissions', ['classId' => $this->otherClass->id]))->assertForbidden();

        $record = AcademicRecord::where('record_code', 'SUB-'.$this->student->id)->sole();
        $this->actingAs($this->teacher)->post(route('portal.teacher.submissions.mark', $record->id))->assertRedirect();
        $this->assertNull($record->fresh()->data['score']);
    }

    // ── 6. Chăm sóc tháng đầu & sinh nhật ─────────────────────────────────

    public function test_first_month_care_tasks_are_created_on_milestones_and_sync_crm_checklist(): void
    {
        $first = $this->makeSession('2026-10-04');
        StudentAttendance::create(['class_id' => $this->classModel->id, 'class_session_id' => $first->id, 'student_id' => $this->student->id,
            'session_date' => '2026-10-04', 'status' => 'present']);
        $customer = CrmCustomer::create(['code' => CrmCustomer::generateCode(), 'name' => 'KH chốt', 'phone' => '0901234567',
            'phone_normalized' => '0901234567', 'branch_id' => $this->branch->id, 'stage' => 'won', 'converted_student_id' => $this->student->id]);

        $this->artisan('students:schedule-first-month-care', ['--date' => '2026-10-07'])->assertExitCode(0);
        $this->artisan('students:schedule-first-month-care', ['--date' => '2026-10-07'])->assertExitCode(0);

        $task = WorkTask::sole();
        $this->assertSame(3, (int) $task->care_milestone);
        $this->assertSame($this->academicStaff->id, (int) $task->assignee_id);
        $this->assertSame($this->manager->id, (int) $task->creator_id);
        $this->assertSame('2026-10-07', $task->due_date->toDateString());
        $this->assertTrue(AdminNotification::where('user_id', $this->academicStaff->id)->exists());

        $this->artisan('students:schedule-first-month-care', ['--date' => '2026-10-11']);
        $this->assertSame([3, 7], WorkTask::orderBy('care_milestone')->pluck('care_milestone')->map(fn ($d) => (int) $d)->all());

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotEmpty($customer->fresh()->care_checklist['first_session_feedback'] ?? null);

        $this->actingAs($this->academicStaff)->get(route('students.show', $this->student->id))
            ->assertOk()->assertSee('Chăm sóc tháng đầu')->assertSee('Bắt đầu học')->assertSee('04/10/2026')
            ->assertSee('Hỏi phản hồi sau buổi học đầu tiên');
    }

    public function test_birthday_skips_dropped_students_and_alerts_teacher_and_academic_staff(): void
    {
        $this->student->update(['dob' => '2015-10-07']);
        $gone = $this->makeStudent('Đã Thôi', $this->classModel);
        $gone->update(['dob' => '2014-10-07', 'status' => 'dropped']);
        $done = $this->makeStudent('Đã Hoàn Thành', $this->classModel, 'completed');
        $done->update(['dob' => '2013-10-07']);

        $this->artisan('students:send-birthday-notifications', ['--date' => '2026-10-07'])->assertExitCode(0);
        $this->artisan('students:send-birthday-notifications', ['--date' => '2026-10-07'])->assertExitCode(0);

        $this->assertSame(1, AcademicRecord::where('record_code', 'like', 'BIRTHDAY-%')->count());
        $this->assertSame(1, AdminNotification::where('type', 'student_birthday')->where('user_id', $this->teacher->id)->count());
        $this->assertSame(1, AdminNotification::where('type', 'student_birthday')->where('user_id', $this->academicStaff->id)->count());
        $this->assertSame(2, AdminNotification::where('type', 'student_birthday')->count());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeStudent(string $name, ClassModel $class, string $status = 'studying'): Student
    {
        static $n = 0;
        $n++;

        return Student::create([
            'name' => $name, 'code' => 'HV-P2A-'.$n, 'phone' => '09010000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'current_class_id' => $class->id, 'branch_id' => $this->branch->id, 'status' => $status,
        ]);
    }

    private function makeSession(string $date, string $start = '18:00', string $end = '19:30', string $type = ClassSession::TYPE_REGULAR, string $status = 'scheduled'): ClassSession
    {
        return ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => $start, 'end_time' => $end, 'type' => $type, 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => $status,
        ]);
    }
}

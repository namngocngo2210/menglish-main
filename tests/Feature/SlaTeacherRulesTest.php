<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\MonthlyReportDue;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * SLA cho Giáo viên: C (check-in trong 24h từ giờ bắt đầu), D (điểm danh ±24h), E (đi muộn / về sớm, ngưỡng 15 phút),
 * F (nhắc báo cáo giảng dạy tháng, hạn Chủ nhật cuối tháng).
 */
class SlaTeacherRulesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $teacher;

    private User $academic;

    private ClassModel $class;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Thứ 4
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở SLA', 'code' => 'SLA', 'is_active' => true]);
        $this->teacher = $this->user('teacher');
        $this->academic = $this->user('academic_staff');
        $course = Course::create(['code' => 'SLA-C', 'name' => 'IELTS SLA', 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->class = ClassModel::create([
            'code' => 'SLA-01', 'name' => 'Lớp SLA', 'course_id' => $course->id, 'branch_id' => $this->branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active', 'max_capacity' => 10,
        ]);
        $this->student = Student::create([
            'name' => 'HV SLA', 'code' => 'HV-SLA-1', 'phone' => '0902000001',
            'current_class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'status' => 'studying',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSession(string $date, string $start = '18:00', string $end = '19:30'): ClassSession
    {
        return ClassSession::create([
            'class_id' => $this->class->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => $start, 'end_time' => $end, 'room' => 'P101',
            'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
    }

    // ───────────── C. Check-in trong 24h từ giờ bắt đầu ─────────────

    public function test_checkin_allowed_for_yesterday_evening_session_within_24h(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $session = $this->makeSession('2026-10-06', '18:00', '19:30'); // hạn 07/10 18:00

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasNoErrors();

        $timesheet = TeacherTimesheet::firstOrFail();
        // Ngày công và kỳ lương theo ngày của buổi, không phải ngày bấm check-in.
        $this->assertSame('2026-10-06', $timesheet->teaching_date->toDateString());
        $this->assertSame(960, $timesheet->late_minutes); // 16 giờ sau giờ bắt đầu
    }

    public function test_checkin_rejected_after_24h_from_session_start(): void
    {
        $session = $this->makeSession('2026-10-06', '09:00', '10:30'); // hạn 07/10 09:00 — đã quá 1 giờ

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasErrors('class_ids');

        $this->assertStringContainsString('Quá hạn chấm công', session('errors')->first('class_ids'));
        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_checkin_boundary_exactly_24h_is_allowed(): void
    {
        $session = $this->makeSession('2026-10-06', '10:00', '11:30'); // hạn đúng 07/10 10:00

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('teacher_timesheets', 1);
    }

    public function test_checkin_early_on_same_day_is_still_allowed_and_not_late(): void
    {
        $session = $this->makeSession('2026-10-07', '18:00', '19:30');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, TeacherTimesheet::firstOrFail()->late_minutes);
    }

    public function test_checkin_locked_period_is_checked_against_session_date(): void
    {
        // Buổi ngày 30/09 (kỳ 9 đã duyệt) check-in sáng 01/10 → bị khóa theo ngày buổi.
        Carbon::setTestNow('2026-10-01 08:00:00');
        PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương T9', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'approved',
        ]);
        $session = $this->makeSession('2026-09-30', '18:00', '19:30');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasErrors('class_ids');
        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_home_lists_open_window_sessions_with_deadline_and_warning(): void
    {
        $evening = $this->makeSession('2026-10-06', '10:10', '11:40'); // hạn 07/10 10:10 → còn 10 phút → cảnh báo
        $today = $this->makeSession('2026-10-07', '18:00', '19:30');
        $expired = $this->makeSession('2026-10-06', '08:00', '09:30'); // quá hạn → không nằm trong danh sách ca

        $this->actingAs($this->teacher)->get(route('teacher.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Teacher/Home')
                ->has('shifts', 2)
                ->where('shifts.0.session_id', $evening->id)
                ->where('shifts.0.checkin.warning', true)
                ->where('shifts.0.checkin.deadline', '10:10 07/10')
                ->where('shifts.0.checkin.remaining_seconds', 600)
                ->where('shifts.1.session_id', $today->id)
                ->where('shifts.1.checkin.warning', false)
                ->where('shifts.1.checkin.expired', false));

        $this->assertNotNull($expired);
    }

    public function test_academic_confirm_scheduled_session_has_no_24h_limit(): void
    {
        $session = $this->makeSession('2026-10-01', '08:00', '09:30'); // quá hạn từ lâu

        $this->actingAs($this->academic)->post(route('payroll.timesheets.sessions.confirm', $session->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teacher_timesheets', ['class_session_id' => $session->id, 'status' => 'valid', 'source' => 'schedule']);
    }

    // ───────────── D. Điểm danh ±24h ─────────────

    public function test_teacher_attendance_blocked_outside_24h_window_but_allowed_inside(): void
    {
        $outside = $this->makeSession('2026-10-05', '08:00', '09:30'); // start+24h đã qua
        $inside = $this->makeSession('2026-10-06', '18:00', '19:30');
        $future = $this->makeSession('2026-10-08', '09:00', '10:30'); // start−24h = 07/10 09:00 → đã mở

        $payload = fn (ClassSession $s) => ['class_session_id' => $s->id, 'status' => [$this->student->id => 'present']];
        $store = route('teacher.attendance.store', $this->class->id);

        $this->actingAs($this->teacher)->post($store, $payload($outside))
            ->assertSessionHasErrors(['session' => 'Ngoài khung ±24h so với giờ bắt đầu buổi học — liên hệ Học vụ để điểm danh.']);
        $this->assertDatabaseCount('student_attendances', 0);

        $this->actingAs($this->teacher)->post($store, $payload($inside))->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post($store, $payload($future))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('student_attendances', 2);

        // Trang điểm danh báo lý do để Vue khóa nút Lưu.
        $this->actingAs($this->teacher)->get(route('teacher.attendance', ['classId' => $this->class->id, 'session' => $outside->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('windowReason', 'Ngoài khung ±24h so với giờ bắt đầu buổi học — liên hệ Học vụ để điểm danh.'));
    }

    public function test_academic_staff_can_record_attendance_any_time(): void
    {
        $old = $this->makeSession('2026-10-01', '08:00', '09:30');

        $this->actingAs($this->academic)->post(route('teacher.attendance.store', $this->class->id), [
            'class_session_id' => $old->id, 'status' => [$this->student->id => 'present'],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_attendances', ['class_session_id' => $old->id, 'status' => 'present']);

        $this->actingAs($this->academic)->get(route('teacher.attendance', ['classId' => $this->class->id, 'session' => $old->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('windowReason', null));
    }

    // ───────────── E. Đi muộn / về sớm ─────────────

    private function timesheet(array $attrs = []): TeacherTimesheet
    {
        return TeacherTimesheet::create($attrs + [
            'user_id' => $this->teacher->id, 'class_id' => $this->class->id, 'teaching_date' => '2026-10-06',
            'scheduled_time' => '18:00-20:00', 'hours' => 2, 'hourly_rate' => 100000, 'type' => 'regular', 'status' => 'valid',
        ]);
    }

    public function test_late_with_notice_is_paid_by_actual_minutes(): void
    {
        $ts = $this->timesheet(['late_minutes' => 30, 'late_notified' => true]); // lịch 120 phút, dạy 90
        $out = $ts->payOutcome();

        $this->assertEquals(150000.0, $out['amount']); // 1,5h × 100.000
        $this->assertTrue($out['counted']);
        $this->assertSame('notified', $out['late_rule']);
    }

    public function test_late_under_threshold_without_notice_deducts_per_minute(): void
    {
        $ts = $this->timesheet(['late_minutes' => 6, 'early_leave_minutes' => 4]); // 10 phút, không báo trước
        $out = $ts->payOutcome();

        $this->assertEquals(200000.0 - 10 * 5000, $out['amount']);
        $this->assertTrue($out['counted']);
        $this->assertEquals(50000.0, $out['late_deduction']);

        $custom = $ts->payOutcome(null, ['late_threshold_minutes' => 15, 'late_deduction_per_minute' => 4000]);
        $this->assertEquals(200000.0 - 10 * 4000, $custom['amount']);
    }

    public function test_late_at_or_over_threshold_without_notice_voids_the_session(): void
    {
        $out = $this->timesheet(['late_minutes' => 15])->payOutcome();

        $this->assertEquals(0.0, $out['amount']);
        $this->assertFalse($out['counted']);
        $this->assertSame('void', $out['late_rule']);
    }

    public function test_per_session_rate_is_prorated_or_deducted_too(): void
    {
        \App\Models\TeacherHourlyRate::create([
            'user_id' => $this->teacher->id, 'hourly_rate' => 300000, 'rate_unit' => 'session',
            'effective_from' => '2026-01-01', 'created_by' => $this->academic->id,
        ]);
        $base = $this->timesheet(['hourly_rate' => null]);
        $this->assertEquals(300000.0, $base->payOutcome()['amount']);

        $notified = $this->timesheet(['hourly_rate' => null, 'teaching_date' => '2026-10-05', 'late_minutes' => 60, 'late_notified' => true]);
        $this->assertEquals(150000.0, $notified->payOutcome()['amount']); // 60/120 phút

        $deduct = $this->timesheet(['hourly_rate' => null, 'teaching_date' => '2026-10-04', 'late_minutes' => 5]);
        $this->assertEquals(275000.0, $deduct->payOutcome()['amount']);
    }

    public function test_payroll_excludes_voided_session_and_records_deduction_details(): void
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-10', 'title' => 'Bảng lương T10', 'month' => 10, 'year' => 2026,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft',
        ]);
        $this->timesheet(['teaching_date' => '2026-10-02']);                          // 200.000
        $this->timesheet(['teaching_date' => '2026-10-03', 'late_minutes' => 10]);    // 150.000
        $this->timesheet(['teaching_date' => '2026-10-04', 'late_minutes' => 20]);    // không tính buổi

        $period->calculatePayrollForPeriod();
        $record = $period->records()->where('user_id', $this->teacher->id)->firstOrFail();

        $this->assertSame(2, (int) $record->teaching_sessions);
        $this->assertEquals(350000.0, (float) $record->teaching_salary);
        $lines = data_get($record->calculation_details, 'late.lines');
        $this->assertCount(2, $lines);
        $this->assertEquals(250000.0, (float) data_get($record->calculation_details, 'late.total_deduction')); // 50.000 + 200.000
    }

    public function test_checkin_records_late_minutes_automatically(): void
    {
        Carbon::setTestNow('2026-10-07 08:12:00');
        $session = $this->makeSession('2026-10-07', '08:00', '09:30');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['session_ids' => [$session->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(12, TeacherTimesheet::firstOrFail()->late_minutes);
    }

    public function test_academic_can_adjust_late_fields_and_manual_entry_accepts_them(): void
    {
        $ts = $this->timesheet(['status' => 'pending_review', 'source' => 'checkin', 'late_minutes' => 20]);

        $this->actingAs($this->academic)->put(route('payroll.timesheets.adjust', $ts->id), [
            'time_in' => '18:00', 'time_out' => '20:00', 'adjustment_reason' => 'GV báo trước qua Zalo',
            'late_minutes' => 20, 'early_leave_minutes' => 0, 'late_notified' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($ts->fresh()->late_notified);

        $this->actingAs($this->academic)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->class->id, 'teaching_date' => '2026-10-05',
            'time_in' => '18:00', 'time_out' => '19:30', 'type' => 'regular', 'notes' => 'GV quên check-in',
            'late_minutes' => 5, 'early_leave_minutes' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('teacher_timesheets', ['source' => 'manual', 'late_minutes' => 5, 'early_leave_minutes' => 3, 'late_notified' => false]);
    }

    public function test_settings_validate_late_deduction_range(): void
    {
        $admin = $this->user('admin');
        $base = ['insurance_rate_percent' => 10.5, 'union_rate_percent' => 0.5, 'academic_kpi_fund' => 2000000];

        $this->actingAs($admin)->post(route('payroll.config.settings.store'), $base + ['late_deduction_per_minute' => 3000])
            ->assertSessionHasErrors('late_deduction_per_minute');
        $this->actingAs($admin)->post(route('payroll.config.settings.store'), $base + ['late_deduction_per_minute' => 6000])
            ->assertSessionHasErrors('late_deduction_per_minute');
        $this->actingAs($admin)->post(route('payroll.config.settings.store'), $base + ['late_deduction_per_minute' => 4500, 'late_threshold_minutes' => 15])
            ->assertSessionHasNoErrors();

        $settings = PayrollPeriod::payrollSettings();
        $this->assertSame(4500.0, $settings['late_deduction_per_minute']);
        $this->assertSame(15, $settings['late_threshold_minutes']);
    }

    // ───────────── F. Nhắc báo cáo giảng dạy tháng ─────────────

    public function test_monthly_due_date_is_last_sunday_of_month(): void
    {
        $this->assertSame('2026-10-25', MonthlyReportDue::dueDate(Carbon::parse('2026-10-07'))->toDateString());
        $this->assertSame('2026-11-29', MonthlyReportDue::dueDate(Carbon::parse('2026-11-30'))->toDateString());
        // Tháng kết thúc đúng vào Chủ nhật → chính ngày đó.
        $this->assertSame('2026-05-31', MonthlyReportDue::dueDate(Carbon::parse('2026-05-10'))->toDateString());
        $this->assertSame('due_minus_3', MonthlyReportDue::reminderKind(Carbon::parse('2026-10-22')));
        $this->assertSame('due_minus_1', MonthlyReportDue::reminderKind(Carbon::parse('2026-10-24')));
        $this->assertSame('due', MonthlyReportDue::reminderKind(Carbon::parse('2026-10-25')));
        $this->assertNull(MonthlyReportDue::reminderKind(Carbon::parse('2026-10-23')));
    }

    public function test_remind_command_notifies_unsubmitted_monthly_staff_once_per_kind(): void
    {
        $submitted = $this->user('teacher');
        StaffReport::create(['user_id' => $submitted->id, 'type' => 'monthly', 'title' => 'BC T10', 'content' => 'x', 'report_date' => '2026-10-10', 'status' => 'submitted']);

        Carbon::setTestNow('2026-10-22 08:00:00');
        $this->artisan('reports:remind-monthly')->assertSuccessful();
        $this->artisan('reports:remind-monthly')->assertSuccessful(); // idempotent

        $this->assertSame(1, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'monthly_report_due')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $submitted->id)->where('type', 'monthly_report_due')->count());
        $this->assertSame(0, AdminNotification::where('user_id', $this->academic->id)->where('type', 'monthly_report_due')->count());

        Carbon::setTestNow('2026-10-24 08:00:00');
        $this->artisan('reports:remind-monthly')->assertSuccessful();
        Carbon::setTestNow('2026-10-25 08:00:00');
        $this->artisan('reports:remind-monthly')->assertSuccessful();
        $this->assertSame(3, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'monthly_report_due')->count());

        Carbon::setTestNow('2026-10-23 08:00:00'); // không phải ngày nhắc
        $this->artisan('reports:remind-monthly')->assertSuccessful();
        $this->assertSame(3, AdminNotification::where('user_id', $this->teacher->id)->where('type', 'monthly_report_due')->count());
    }

    public function test_my_reports_shows_monthly_due_and_state(): void
    {
        $this->actingAs($this->teacher)->get(route('reports.my'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('monthlyDue.due_label', 'Chủ nhật 25/10')
                ->where('monthlyDue.submitted', false));

        StaffReport::create(['user_id' => $this->teacher->id, 'type' => 'monthly', 'title' => 'BC T10', 'content' => 'x', 'report_date' => '2026-10-07', 'status' => 'submitted']);
        $this->actingAs($this->teacher)->get(route('reports.my'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('monthlyDue.submitted', true));

        // Học vụ báo cáo ngày → không có hạn tháng.
        $this->actingAs($this->academic)->get(route('reports.my'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('monthlyDue', null));
    }
}

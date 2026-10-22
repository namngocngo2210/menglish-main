<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\TeacherHourlyRate;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Chấm công: chấm tay bắt buộc lý do + giờ vào/ra, gắn buổi học thật,
 * chặn chấm trùng, check-in chỉ cho buổi có thật, đơn giá GV theo ngày hiệu lực.
 */
class Phase3TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $academicStaff;

    private User $manager;

    private User $teacher;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở P3', 'code' => 'P3', 'is_active' => true]);
        $this->academicStaff = $this->userWithRole('academic_staff');
        $this->manager = $this->userWithRole('manager');
        $this->teacher = $this->userWithRole('teacher_parttime', ['name' => 'GV Phase 3']);

        $course = Course::create(['code' => 'P3-C', 'name' => 'IELTS P3', 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'P3-01', 'name' => 'Lớp P3', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function classSession(string $date, string $start = '18:00', string $end = '20:00', ?int $teacherId = null): ClassSession
    {
        return ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'date' => $date,
            'start_time' => $start, 'end_time' => $end,
            'teacher_id' => $teacherId ?? $this->teacher->id, 'status' => 'scheduled',
        ]);
    }

    private function manualPayload(array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-20', 'time_in' => '18:00', 'time_out' => '19:30',
            'type' => 'regular', 'notes' => 'GV quên check-in trên cổng giáo viên',
        ];
    }

    public function test_manual_timesheet_requires_reason_and_time_in_out(): void
    {
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-20', 'type' => 'regular',
        ])->assertSessionHasErrors(['notes', 'time_in', 'time_out']);

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload([
            'time_in' => '18:00', 'time_out' => '18:15',
        ]))->assertSessionHasErrors('time_out');

        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_manual_timesheet_computes_hours_and_links_real_session(): void
    {
        $session = $this->classSession('2026-08-20');

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload())
            ->assertRedirect(route('payroll.timesheets.teachers'))
            ->assertSessionHasNoErrors();

        $timesheet = TeacherTimesheet::firstOrFail();
        $this->assertEquals(1.5, $timesheet->hours);
        $this->assertSame($session->id, $timesheet->class_session_id);
        $this->assertSame('18:00', $timesheet->checkin_time);
        $this->assertSame('19:30', $timesheet->checkout_time);
        $this->assertSame('manual', $timesheet->source);
        $this->assertSame('pending_review', $timesheet->status);
        $this->assertSame('GV quên check-in trên cổng giáo viên', $timesheet->notes);
    }

    public function test_manual_timesheet_without_session_is_kept_unlinked(): void
    {
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload(['type' => 'workshop']))
            ->assertSessionHasNoErrors();

        $this->assertNull(TeacherTimesheet::firstOrFail()->class_session_id);
    }

    public function test_two_manual_entries_for_same_class_and_day_are_rejected(): void
    {
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload())
            ->assertSessionHasNoErrors();
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload(['time_in' => '19:00', 'time_out' => '21:00']))
            ->assertSessionHasErrors('teaching_date');

        $this->assertDatabaseCount('teacher_timesheets', 1);
    }

    public function test_manual_entry_after_checkin_for_same_session_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 17:55:00'));
        $this->classSession('2026-08-20');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('teacher_timesheets', 1);

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload())
            ->assertSessionHasErrors('teaching_date');
        $this->assertDatabaseCount('teacher_timesheets', 1);
    }

    public function test_rejected_timesheet_can_be_replaced_by_manual_entry(): void
    {
        $session = $this->classSession('2026-08-20');
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'class_session_id' => $session->id,
            'teaching_date' => '2026-08-20', 'hours' => 2, 'type' => 'regular', 'source' => 'checkin', 'status' => 'invalid',
        ]);

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.manual.store'), $this->manualPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('teacher_timesheets', 1);
        $timesheet = TeacherTimesheet::firstOrFail();
        $this->assertSame('manual', $timesheet->source);
        $this->assertSame('pending_review', $timesheet->status);
    }

    public function test_checkin_without_real_session_does_not_create_timesheet(): void
    {
        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasErrors('class_ids');

        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_checkin_uses_session_duration_and_skips_manual_entries(): void
    {
        $this->travelTo(Carbon::parse('2026-08-21 08:00:00'));
        $session = $this->classSession('2026-08-21', '08:00', '09:30');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasNoErrors();
        $timesheet = TeacherTimesheet::firstOrFail();
        $this->assertEquals(1.5, $timesheet->hours);
        $this->assertSame($session->id, $timesheet->class_session_id);
        $this->assertSame('checkin', $timesheet->source);

        // Buổi đã được chấm tay → check-in không ghi đè bản ghi chấm tay
        $timesheet->update(['source' => 'manual', 'notes' => 'Chấm tay', 'status' => 'valid']);
        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasErrors('class_ids');
        $this->assertSame('valid', $timesheet->fresh()->status);
        $this->assertSame('manual', $timesheet->fresh()->source);
    }

    public function test_academic_staff_cannot_checkin_for_a_class_session_assigned_to_someone_else(): void
    {
        $this->travelTo(Carbon::parse('2026-08-22 08:00:00'));
        $this->classSession('2026-08-22', '08:00', '10:00');

        $this->actingAs($this->academicStaff)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasErrors('class_ids');
        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_sync_history_shows_honest_empty_state(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get(route('payroll.timesheets.sync-history'))
            ->assertOk()
            ->assertSee('Chưa kết nối nguồn đồng bộ')
            ->assertDontSee('Đồng bộ thành công');
    }

    // ───────────── Đơn giá GV theo ngày hiệu lực ─────────────

    public function test_teacher_rate_history_is_resolved_by_teaching_date(): void
    {
        $this->teacher->update(['hourly_rate' => 200000]);
        TeacherHourlyRate::create(['user_id' => $this->teacher->id, 'hourly_rate' => 300000, 'effective_from' => '2026-08-01']);
        TeacherHourlyRate::create(['user_id' => $this->teacher->id, 'hourly_rate' => 350000, 'effective_from' => '2026-08-16']);

        foreach (['2026-07-20', '2026-08-10', '2026-08-20'] as $date) {
            TeacherTimesheet::create([
                'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
                'teaching_date' => $date, 'hours' => 2, 'type' => 'regular', 'status' => 'valid',
            ]);
        }
        // Đơn giá ghi rõ trên ca dạy vẫn được ưu tiên
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-25', 'hours' => 1, 'hourly_rate' => 500000, 'type' => 'sub', 'status' => 'valid',
        ]);

        $rates = TeacherTimesheet::orderBy('teaching_date')->get()->map->effectiveHourlyRate()->all();
        $this->assertEquals([200000, 300000, 350000, 500000], $rates);

        $period = PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();

        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->teacher->id)->firstOrFail();
        // 2h × 300k + 2h × 350k + 1h × 500k
        $this->assertEquals(1800000, $record->teaching_salary);
    }

    public function test_manager_can_add_teacher_rate_as_new_version_and_see_history(): void
    {
        $this->actingAs($this->manager)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'hourly_rate' => 300000, 'effective_from' => '2026-08-01', 'note' => 'Ký HĐ',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'hourly_rate' => 350000, 'effective_from' => '2026-09-01', 'note' => 'Tăng bậc',
        ])->assertSessionHasNoErrors();
        // Trùng ngày hiệu lực → lỗi (phải thêm phiên bản mới với ngày khác)
        $this->actingAs($this->manager)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'hourly_rate' => 999000, 'effective_from' => '2026-09-01',
        ])->assertSessionHasErrors('effective_from');

        $this->assertSame(2, TeacherHourlyRate::where('user_id', $this->teacher->id)->count());
        $this->assertSame($this->manager->id, TeacherHourlyRate::first()->created_by);

        $this->actingAs($this->manager)->get(route('payroll.config.teacher-rates', ['teacher_id' => $this->teacher->id]))
            ->assertOk()
            ->assertSee('GV Phase 3')
            ->assertSee('Tăng bậc')
            ->assertSee('350.000')
            ->assertSee('01/08/2026');

        $this->actingAs($this->teacher)->post(route('payroll.config.teacher-rates.personal.store'), [
            'user_id' => $this->teacher->id, 'hourly_rate' => 1000000, 'effective_from' => '2026-10-01',
        ])->assertForbidden();
    }

    public function test_bulk_confirm_creates_valid_schedule_timesheets_for_selected_sessions(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $evening = $this->classSession('2026-10-20');
        $morning = $this->classSession('2026-10-21', '08:00', '09:30');
        $future = $this->classSession('2026-10-25');
        PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'approved',
        ]);
        $locked = $this->classSession('2026-08-20');
        $notMine = $this->classSession('2026-10-22', teacherId: $this->manager->id);

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.sessions.bulk-confirm'), [
            'user_id' => $this->teacher->id, 'session_ids' => [$evening->id, $morning->id, $future->id, $locked->id, $notMine->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $message) => str_contains($message, 'Đã xác nhận 2 buổi theo lịch')
            && str_contains($message, '25/10/2026') && str_contains($message, '20/08/2026') && str_contains($message, '22/10/2026'));

        $rows = TeacherTimesheet::orderBy('teaching_date')->get();
        $this->assertSame([$evening->id, $morning->id], $rows->pluck('class_session_id')->all());
        foreach ($rows as $row) {
            $this->assertSame([$this->teacher->id, TeacherTimesheet::SOURCE_SCHEDULE, 'valid', $this->academicStaff->id],
                [(int) $row->user_id, $row->source, $row->status, (int) $row->reviewed_by]);
        }
        $this->assertEquals([2.0, 1.5], $rows->pluck('hours')->map(fn ($h) => (float) $h)->all());

        // Chọn lại các buổi đã có công: không ghi thêm, báo lỗi vì không còn buổi nào để xác nhận.
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.sessions.bulk-confirm'), [
            'user_id' => $this->teacher->id, 'session_ids' => [$future->id],
        ])->assertSessionHasErrors('session_ids');
        $this->assertSame(2, TeacherTimesheet::count());
    }

    public function test_missing_sessions_list_offers_bulk_confirm_to_manual_recorders(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $this->classSession('2026-10-20');
        $page = route('payroll.timesheets.teachers', ['user_id' => $this->teacher->id, 'month' => '2026-10']);

        $this->actingAs($this->academicStaff)->get($page)->assertOk()
            ->assertSee('Buổi thiếu chấm công (1)')->assertSee('data-bulk-confirm', false)->assertSee('Xác nhận theo lịch');
    }

    public function test_bulk_confirm_approves_pending_checkin_instead_of_duplicating(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $session = $this->classSession('2026-10-20');
        $checkin = TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'class_session_id' => $session->id,
            'teaching_date' => '2026-10-20', 'scheduled_time' => '18:00-20:00', 'checkin_time' => '18:05', 'hours' => 2,
            'type' => 'regular', 'source' => TeacherTimesheet::SOURCE_CHECKIN, 'status' => 'pending_review',
        ]);

        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.sessions.bulk-confirm'), [
            'user_id' => $this->teacher->id, 'session_ids' => [$session->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, TeacherTimesheet::count());
        $this->assertSame(['valid', TeacherTimesheet::SOURCE_CHECKIN, '18:05'], [$checkin->fresh()->status, $checkin->fresh()->source, substr((string) $checkin->fresh()->checkin_time, 0, 5)]);
    }

    public function test_bulk_confirm_requires_manual_record_permission_and_branch_scope(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $session = $this->classSession('2026-10-20');
        $payload = ['user_id' => $this->teacher->id, 'session_ids' => [$session->id]];
        $this->actingAs($this->teacher)->post(route('payroll.timesheets.sessions.bulk-confirm'), $payload)->assertForbidden();

        $otherBranch = Branch::create(['name' => 'Cơ sở khác', 'code' => 'P3-X', 'is_active' => true]);
        $this->classModel->update(['branch_id' => $otherBranch->id]);
        $session->update(['branch_id' => $otherBranch->id]);
        $this->actingAs($this->academicStaff)->post(route('payroll.timesheets.sessions.bulk-confirm'), $payload)->assertForbidden();
        $this->assertSame(0, TeacherTimesheet::count());
    }
}

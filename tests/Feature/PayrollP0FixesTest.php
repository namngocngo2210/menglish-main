<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\TimesheetSyncLog;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hồi quy cho các lỗi P0/P1 về tiền của phân hệ Lương – Chấm công – Phạt.
 */
class PayrollP0FixesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private User $manager;

    private User $teacher;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở P0', 'code' => 'P0', 'is_active' => true]);

        $this->admin = $this->userWithRole('admin');
        $this->accountant = $this->userWithRole('accountant');
        $this->manager = $this->userWithRole('manager');
        $this->teacher = $this->userWithRole('teacher', ['name' => 'GV Lương P0']);

        $course = Course::create(['code' => 'P0-C', 'name' => 'IELTS P0', 'tuition_fee' => 1000000, 'is_active' => true]);
        $this->classModel = ClassModel::create([
            'code' => 'P0-01', 'name' => 'Lớp P0', 'course_id' => $course->id,
            'branch_id' => $this->branch->id, 'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function period(int $month = 8, int $year = 2026, string $status = 'draft'): PayrollPeriod
    {
        $start = Carbon::create($year, $month, 1);

        return PayrollPeriod::create([
            'code' => sprintf('PR-%d-%02d', $year, $month),
            'title' => "Bảng lương Tháng {$month}/{$year}",
            'month' => $month, 'year' => $year,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
            'status' => $status,
        ]);
    }

    private function timesheet(User $user, string $date, float $hours, ?float $rate = null, string $status = 'valid'): TeacherTimesheet
    {
        return TeacherTimesheet::create([
            'user_id' => $user->id, 'class_id' => $this->classModel->id,
            'teaching_date' => $date, 'hours' => $hours, 'hourly_rate' => $rate,
            'type' => 'regular', 'status' => $status,
        ]);
    }

    private function penalty(User $user, string $date, float $amount, string $status = 'fined'): Penalty
    {
        static $seq = 0;
        $seq++;

        return Penalty::create([
            'code' => 'BB-P0-'.$seq, 'user_id' => $user->id, 'violation_type' => 'Đi muộn',
            'violation_date' => $date, 'amount' => $amount, 'status' => $status,
        ]);
    }

    private function record(PayrollPeriod $period, User $user): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $user->id)->firstOrFail();
    }

    // ───────────── 1. Hoa hồng được lưu & không bị xoá khi sửa GVNN ─────────────

    public function test_commission_is_persisted_in_record_and_net(): void
    {
        $sales = $this->userWithRole('sales_consultant', ['name' => 'Sales Hoa Hồng']);
        CommissionTier::create(['tier_name' => 'Mức 1', 'min_revenue' => 0, 'new_sale_percent' => 5, 'renew_percent' => 0, 'bonus_amount' => 200000]);
        // Phase 3 (A6): căn cứ hoa hồng = tiền thực thu (phiếu duyệt trong kỳ), không phải deal_value
        $student = \App\Models\Student::create(['code' => 'HV-P0-1', 'name' => 'HV Won', 'phone' => '0900000001', 'branch_id' => $this->branch->id]);
        CrmCustomer::create([
            'code' => 'KH-P0-1', 'name' => 'Lead Won', 'phone' => '0900000001', 'stage' => 'won',
            'deal_value' => 99000000, 'branch_id' => $this->branch->id,
            'assigned_user_id' => $sales->id, 'commission_user_id' => $sales->id,
            'converted_at' => '2026-08-10 10:00:00', 'converted_student_id' => $student->id,
        ]);
        $tuition = \App\Models\StudentTuition::create([
            'student_id' => $student->id, 'branch_id' => $this->branch->id,
            'total_amount' => 20000000, 'final_amount' => 20000000, 'paid_amount' => 0, 'debt_amount' => 20000000, 'status' => 'unpaid',
        ]);
        \App\Models\TuitionReceipt::create([
            'receipt_number' => 'PT-P0-1', 'student_tuition_id' => $tuition->id, 'student_id' => $student->id,
            'amount' => 20000000, 'payment_method' => 'cash', 'payment_date' => '2026-08-10',
            'status' => 'approved', 'approved_at' => '2026-08-10 10:00:00',
        ]);

        $period = $this->period();
        $period->calculatePayrollForPeriod();

        $record = $this->record($period, $sales);
        // 20M × 5% + 200k thưởng bậc = 1.2M
        $this->assertEquals(1200000, $record->commission_bonus);
        $this->assertEquals(1200000, $record->net_salary);
        $this->assertEquals(1200000, $period->fresh()->total_amount);
    }

    public function test_foreign_teacher_adjustment_keeps_commission_and_updates_period_total(): void
    {
        $period = $this->period();
        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->teacher->id,
            'teaching_salary' => 3000000, 'commission_bonus' => 1000000, 'net_salary' => 4000000,
        ]);
        $period->update(['total_amount' => 4000000]);

        $this->actingAs($this->admin)->post(route('payroll.records.update', $record->id), [
            'foreign_teacher_sessions_count' => 2,
            'foreign_teacher_deduction_rate' => 50000,
        ])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertEquals(1000000, $record->commission_bonus);
        // 3M + 1M hoa hồng − 2 × 50k GVNN
        $this->assertEquals(3900000, $record->net_salary);
        $this->assertEquals(3900000, $period->fresh()->total_amount);
    }

    public function test_period_views_show_commission_column_that_reconciles_with_net(): void
    {
        $period = $this->period(8, 2026, 'reviewing');
        foreach (['fulltime', 'academic', 'operations'] as $department) {
            $user = User::factory()->create(['name' => "NV {$department}", 'is_active' => true]);
            PayrollRecord::create([
                'payroll_period_id' => $period->id, 'user_id' => $user->id, 'department' => $department,
                'base_salary' => 5000000, 'teaching_salary' => 1000000, 'kpi_bonus' => 300000,
                'allowance' => 500000, 'commission_bonus' => 1234000,
                'insurance_deduction' => 525000, 'penalty_deduction' => 100000,
                'net_salary' => 7409000,
            ]);
        }

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()->assertSee('Hoa hồng')->assertSee('1,234,000');

        foreach (['fulltime', 'academic', 'operations'] as $department) {
            $this->actingAs($this->admin)->get(route("payroll.periods.{$department}", $period->id))
                ->assertOk()
                ->assertSee('Hoa hồng')
                ->assertSee('1,234,000')   // hoa hồng
                ->assertSee('-625,000')    // tổng giảm trừ = BHXH + phạt
                ->assertSee('7,409,000');
        }
    }

    // ───────────── 2. Đơn giá giờ dạy fallback ─────────────

    public function test_timesheet_rate_falls_back_to_user_rate_then_default(): void
    {
        $withRate = User::factory()->create(['is_active' => true, 'hourly_rate' => 200000]);
        $withoutRate = User::factory()->create(['is_active' => true, 'hourly_rate' => 0]);
        $explicit = User::factory()->create(['is_active' => true, 'hourly_rate' => 200000]);

        $this->timesheet($withRate, '2026-08-05', 2);
        $this->timesheet($withoutRate, '2026-08-05', 2);
        $this->timesheet($explicit, '2026-08-05', 2, 450000);

        $this->assertNull(TeacherTimesheet::where('user_id', $withRate->id)->value('hourly_rate'));

        $period = $this->period();
        $period->calculatePayrollForPeriod();

        $this->assertEquals(400000, $this->record($period, $withRate)->teaching_salary);    // 2h × user 200k
        $this->assertEquals(500000, $this->record($period, $withoutRate)->teaching_salary); // 2h × mặc định 250k
        $this->assertEquals(900000, $this->record($period, $explicit)->teaching_salary);    // 2h × 450k trên timesheet
    }

    public function test_manual_timesheet_without_rate_is_stored_null_and_form_has_no_prefill(): void
    {
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-20', 'time_in' => '18:00', 'time_out' => '20:00', 'type' => 'regular',
            'notes' => 'GV quên check-in',
        ])->assertSessionHasNoErrors();

        $this->assertNull(TeacherTimesheet::where('user_id', $this->teacher->id)->value('hourly_rate'));

        $this->actingAs($this->admin)->get(route('payroll.timesheets.manual'))
            ->assertOk()
            ->assertDontSee('value="300000"', false);
    }

    public function test_checkin_leaves_hourly_rate_null(): void
    {
        // Phase 3: check-in chỉ tính công khi có buổi học thật hôm nay.
        \App\Models\ClassSession::create([
            'class_id' => $this->classModel->id, 'branch_id' => $this->branch->id, 'date' => now()->toDateString(),
            'start_time' => '18:00', 'end_time' => '20:00', 'teacher_id' => $this->teacher->id, 'status' => 'scheduled',
        ]);
        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertRedirect();

        $timesheet = TeacherTimesheet::where('user_id', $this->teacher->id)->firstOrFail();
        $this->assertNull($timesheet->hourly_rate);
    }

    // ───────────── 3. Phạt chỉ đóng dấu "deducted" khi thực sự đã trừ ─────────────

    public function test_approve_marks_only_penalties_included_in_calculation(): void
    {
        $withRecord = User::factory()->create(['is_active' => true, 'hourly_rate' => 300000]);
        $this->timesheet($withRecord, '2026-08-05', 2);
        $included = $this->penalty($withRecord, '2026-08-10', 100000);

        // Nhân sự không có lương cứng/giờ dạy/hoa hồng => không có bản ghi lương
        $noRecord = User::factory()->create(['is_active' => true]);
        $excluded = $this->penalty($noRecord, '2026-08-10', 70000);

        $period = $this->period();
        $period->calculatePayrollForPeriod();

        $record = $this->record($period, $withRecord);
        $this->assertEquals(100000, $record->penalty_deduction);
        $this->assertEquals(500000, $record->net_salary); // 600k − 100k
        $this->assertSame($record->id, $included->fresh()->payroll_record_id);
        $this->assertNull($excluded->fresh()->payroll_record_id);

        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $period->fresh()->status);
        $this->assertSame('deducted', $included->fresh()->status);
        $this->assertSame('fined', $excluded->fresh()->status);
    }

    public function test_approve_is_blocked_when_penalties_or_timesheets_changed_after_calculation(): void
    {
        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $teacher = User::factory()->create(['is_active' => true, 'hourly_rate' => 300000]);
        $this->timesheet($teacher, '2026-08-05', 2);

        $period = $this->period();
        $period->calculatePayrollForPeriod();

        // Biên bản phạt quyết sau khi tính lương → phải tính lại mới được duyệt
        $this->travelTo(Carbon::parse('2026-09-02 10:00:00'));
        $late = $this->penalty($teacher, '2026-08-20', 50000);

        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasErrors('period');
        $this->assertSame('reviewing', $period->fresh()->status);
        $this->assertSame('fined', $late->fresh()->status);

        $this->travelTo(Carbon::parse('2026-09-02 11:00:00'));
        $period->calculatePayrollForPeriod();
        $this->assertEquals(550000, $this->record($period, $teacher)->net_salary);

        // Chấm công được duyệt lại sau lần tính → cũng chặn
        $this->travelTo(Carbon::parse('2026-09-02 12:00:00'));
        $this->timesheet($teacher, '2026-08-06', 1);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasErrors('period');

        $this->travelTo(Carbon::parse('2026-09-02 13:00:00'));
        $period->calculatePayrollForPeriod();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $period->fresh()->status);
        $this->assertSame('deducted', $late->fresh()->status);
    }

    // ───────────── 4. Kỳ đã duyệt/chi trả bị khoá hoàn toàn ─────────────

    public function test_is_locked_for_covers_only_approved_and_paid_periods(): void
    {
        $this->period(7, 2026, 'approved');
        $this->period(8, 2026, 'paid');
        $this->period(9, 2026, 'reviewing');

        $this->assertTrue(PayrollPeriod::isLockedFor(Carbon::parse('2026-07-31')));
        $this->assertTrue(PayrollPeriod::isLockedFor(Carbon::parse('2026-08-01')));
        $this->assertFalse(PayrollPeriod::isLockedFor(Carbon::parse('2026-09-15')));
        $this->assertFalse(PayrollPeriod::isLockedFor(Carbon::parse('2026-10-01')));
    }

    public function test_timesheet_and_penalty_writes_are_rejected_in_locked_period(): void
    {
        $this->period(8, 2026, 'approved');

        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-08-20', 'time_in' => '18:00', 'time_out' => '20:00', 'type' => 'regular',
            'notes' => 'GV quên check-in',
        ])->assertSessionHasErrors('teaching_date');
        $this->assertDatabaseCount('teacher_timesheets', 0);

        $pending = $this->timesheet($this->teacher, '2026-08-21', 2, null, 'pending_review');
        $this->actingAs($this->admin)->post(route('payroll.timesheets.review', $pending->id), ['decision' => 'valid'])
            ->assertSessionHasErrors('teaching_date');
        $this->assertSame('pending_review', $pending->fresh()->status);

        $this->actingAs($this->admin)->post(route('penalties.store'), [
            'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn',
            'violation_date' => '2026-08-15', 'amount' => 100000,
        ])->assertSessionHasErrors('violation_date');
        $this->assertDatabaseCount('penalties', 0);

        $penalty = $this->penalty($this->teacher, '2026-08-15', 100000, 'pending');
        $this->actingAs($this->admin)->post(route('penalties.confirm', $penalty->id), ['decision' => 'fine', 'amount' => 100000])
            ->assertSessionHasErrors('violation_date');
        $this->assertSame('pending', $penalty->fresh()->status);

        // Ngoài kỳ đã khoá thì vẫn cho phép bình thường
        $this->actingAs($this->admin)->post(route('penalties.store'), [
            'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn',
            'violation_date' => '2026-09-15', 'amount' => 100000,
        ])->assertSessionHasNoErrors();
    }

    public function test_checkin_is_rejected_when_today_is_in_locked_period(): void
    {
        $this->period(now()->month, now()->year, 'approved');

        $this->actingAs($this->teacher)->post(route('teacher.checkin'), ['class_ids' => [$this->classModel->id]])
            ->assertSessionHasErrors('class_ids');
        $this->assertDatabaseCount('teacher_timesheets', 0);
    }

    public function test_show_page_hides_adjustment_on_locked_period_and_shows_paid_badge(): void
    {
        $period = $this->period(8, 2026, 'paid');
        PayrollRecord::create(['payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'net_salary' => 1000000]);

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertSee('Đã trả')
            ->assertDontSee('Trừ GVNN');

        $period->update(['status' => 'reviewing']);
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertSee('Trừ GVNN');
    }

    // ───────────── 5. Tính lại không để lại bản ghi cũ ─────────────

    public function test_recalculation_removes_stale_records_and_recomputes_total(): void
    {
        $timesheet = $this->timesheet($this->teacher, '2026-08-05', 2, 300000);
        $other = User::factory()->create(['is_active' => true]);
        $this->timesheet($other, '2026-08-05', 1, 300000);

        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->assertSame(2, $period->records()->count());
        $this->assertEquals(900000, $period->fresh()->total_amount);

        $timesheet->update(['status' => 'invalid']);
        $period->calculatePayrollForPeriod();

        $this->assertSame(1, $period->records()->count());
        $this->assertDatabaseMissing('payroll_records', ['payroll_period_id' => $period->id, 'user_id' => $this->teacher->id]);
        $period->refresh();
        $this->assertSame(1, $period->total_staff);
        $this->assertEquals(300000, $period->total_amount);
    }

    // ───────────── 6. Phân quyền lương ─────────────

    public function test_only_admin_can_approve_and_mark_paid(): void
    {
        $this->timesheet($this->teacher, '2026-08-05', 2, 300000);
        $period = $this->period();

        $this->actingAs($this->accountant)->post(route('payroll.periods.calculate', $period->id))->assertRedirect();
        $this->assertSame('reviewing', $period->fresh()->status);
        $this->actingAs($this->accountant)->get(route('payroll.periods.show', $period->id))->assertOk();

        $this->actingAs($this->accountant)->post(route('payroll.periods.approve', $period->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('payroll.periods.approve', $period->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('payroll.periods.calculate', $period->id))->assertForbidden();
        $this->actingAs($this->manager)->get(route('payroll.periods.show', $period->id))->assertOk();

        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('payroll.periods.mark-paid', $period->id))->assertForbidden();
        $this->actingAs($this->admin)->post(route('payroll.periods.mark-paid', $period->id))->assertRedirect();
        $this->assertSame('paid', $period->fresh()->status);
    }

    // ───────────── 7. Lương của tôi ─────────────

    public function test_my_salary_shows_only_approved_or_paid_periods_and_reconciles(): void
    {
        $draft = $this->period(9, 2026, 'reviewing');
        PayrollRecord::create(['payroll_period_id' => $draft->id, 'user_id' => $this->teacher->id, 'net_salary' => 9999000]);

        $this->actingAs($this->teacher)->get(route('portal.my-salary'))
            ->assertOk()
            ->assertDontSee('9,999,000')
            ->assertDontSee('Đã xác thực dữ liệu');

        $approved = $this->period(8, 2026, 'approved');
        PayrollRecord::create([
            'payroll_period_id' => $approved->id, 'user_id' => $this->teacher->id,
            'teaching_salary' => 3000000, 'commission_bonus' => 700000,
            'penalty_deduction' => 100000, 'foreign_teacher_deduction' => 50000,
            'net_salary' => 3550000,
        ]);
        $paid = $this->period(7, 2026, 'paid');
        PayrollRecord::create(['payroll_period_id' => $paid->id, 'user_id' => $this->teacher->id, 'teaching_salary' => 2100000, 'net_salary' => 2100000]);

        // Mặc định là kỳ mới nhất đã duyệt
        $this->actingAs($this->teacher)->get(route('portal.my-salary'))
            ->assertOk()
            ->assertSee('Bảng lương Tháng 8/2026')
            ->assertSee('3,700,000')  // tổng thu nhập gồm hoa hồng
            ->assertSee('150,000')    // tổng trừ gồm GVNN
            ->assertSee('3,550,000')
            ->assertSee('Đã duyệt')
            ->assertDontSee('9,999,000');

        $this->actingAs($this->teacher)->get(route('portal.my-salary', ['period_id' => $paid->id]))
            ->assertOk()
            ->assertSee('2,100,000')
            ->assertSee('Đã chi trả');

        // Không xem được kỳ chưa duyệt bằng cách đổi period_id
        $this->actingAs($this->teacher)->get(route('portal.my-salary', ['period_id' => $draft->id]))
            ->assertNotFound();
    }

    // ───────────── 8. Lịch sử đồng bộ máy chấm công ─────────────

    public function test_sync_history_reads_real_columns_and_status(): void
    {
        TimesheetSyncLog::create(['device_name' => 'FaceID-01', 'records_count' => 42, 'matched_count' => 40, 'status' => 'failed']);

        $this->actingAs($this->admin)->get(route('payroll.timesheets.sync-history'))
            ->assertOk()
            ->assertSee('42 lượt')
            ->assertSee('40 / 42')
            ->assertSee('Thất bại')
            ->assertDontSee('Đồng bộ thành công');
    }
}

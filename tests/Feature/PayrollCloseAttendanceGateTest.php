<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodLifecycle;
use App\Services\TeachingShifts\TimesheetSoftDelete;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

class PayrollCloseAttendanceGateTest extends TestCase
{
    use FinalizesPayrollKpi, RefreshDatabase;

    private User $admin;

    private User $teacher;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-12 09:00:00'));
        $branch = Branch::create(['code' => 'R13', 'name' => 'Payroll gate', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create(['name' => 'Gate Teacher', 'branch_id' => $branch->id, 'is_active' => true, 'base_salary' => 0]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->classModel = ClassModel::create(['code' => 'R13-1', 'name' => 'Gate class', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function row(array $attributes = []): TeacherTimesheet
    {
        return TeacherTimesheet::create($attributes + ['user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-11-12', 'hours' => 2, 'hourly_rate' => 300000, 'type' => 'regular', 'status' => 'valid']);
    }

    private function calculatedPeriod(array $attributes = []): PayrollPeriod
    {
        $period = PayrollPeriod::create($attributes + ['code' => 'R13-P', 'title' => 'Attendance close gate', 'month' => 11,
            'year' => 2026, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => PayrollPeriod::STATUS_DRAFT]);
        $period->calculatePayrollForPeriod();
        $this->finalizeKpi($period);

        return $period->fresh();
    }

    public function test_gate_returns_400_lists_rows_and_rolls_back_status_records_and_locks(): void
    {
        $draft = $this->row(['reconcile_status' => 'nhap', 'status' => 'pending_review']);
        $closed = $this->row(['reconcile_status' => 'da_chot', 'teaching_date' => '2026-11-13']);
        $period = $this->calculatedPeriod();
        $reason = app(PayrollPeriodLifecycle::class)->blockReason($period, PayrollPeriod::STATUS_CLOSED);
        $this->assertStringContainsString('Gate Teacher – 12/11 – Gate class', $reason);
        $this->actingAs($this->admin)->postJson(route('payroll.periods.approve', $period->id))
            ->assertStatus(400)->assertJsonPath('message', $reason);
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $this->assertSame('pending', $period->records()->firstOrFail()->status);
        $this->assertSame(0, $period->transitions()->count());
        $this->assertFalse($draft->fresh()->payroll_locked);
        $this->assertFalse($closed->fresh()->payroll_locked);
        $actions = app(PayrollPeriodLifecycle::class)->actions($period, $this->admin);
        $this->assertFalse($actions[0]['enabled']);
        $this->assertSame($reason, $actions[0]['reason']);
    }

    public function test_gate_lists_at_most_ten_rows_and_counts_the_rest(): void
    {
        for ($day = 1; $day <= 12; $day++) {
            $this->row(['reconcile_status' => 'cho_doi_soat', 'teaching_date' => sprintf('2026-11-%02d', $day)]);
        }
        $period = $this->calculatedPeriod();
        $reason = app(PayrollPeriodLifecycle::class)->blockReason($period, PayrollPeriod::STATUS_CLOSED);
        $this->assertSame(10, substr_count($reason, 'Gate Teacher –'));
        $this->assertStringContainsString('và 2 dòng khác', $reason);
    }

    public function test_closed_and_legacy_rows_lock_without_timestamp_changes_and_reopen_unlocks(): void
    {
        $closed = $this->row(['reconcile_status' => 'da_chot']);
        $legacy = $this->row(['teaching_date' => '2026-11-13']);
        $invalid = $this->row(['teaching_date' => '2026-11-14', 'reconcile_status' => 'nhap', 'status' => 'invalid']);
        $deleted = $this->row(['teaching_date' => '2026-11-15', 'reconcile_status' => 'da_chot', 'bi_xoa_boi_hoc_vu' => true]);
        $outside = $this->row(['teaching_date' => '2026-10-31', 'reconcile_status' => 'da_chot']);
        $period = $this->calculatedPeriod();
        $timestamp = $closed->getRawOriginal('updated_at');
        $this->travelTo(now()->addMinute());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertTrue($closed->fresh()->payroll_locked);
        $this->assertTrue($legacy->fresh()->payroll_locked);
        $this->assertFalse($invalid->fresh()->payroll_locked);
        $this->assertFalse(TeacherTimesheet::withSoftDeleted()->findOrFail($deleted->id)->payroll_locked);
        $this->assertFalse($outside->fresh()->payroll_locked);
        $this->assertSame($timestamp, $closed->fresh()->getRawOriginal('updated_at'));
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->post(route('payroll.periods.reopen', $period->id))->assertSessionHasNoErrors();
        $this->assertFalse($closed->fresh()->payroll_locked);
        $this->assertFalse($legacy->fresh()->payroll_locked);
        $this->assertSame($timestamp, $closed->fresh()->getRawOriginal('updated_at'));
    }

    public function test_soft_deleted_and_invalid_open_rows_do_not_block_legacy_period(): void
    {
        $this->row();
        $this->row(['reconcile_status' => 'nhap', 'status' => 'invalid']);
        $this->row(['reconcile_status' => 'cho_doi_soat', 'bi_xoa_boi_hoc_vu' => true]);
        $period = $this->calculatedPeriod();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame(PayrollPeriod::STATUS_CLOSED, $period->fresh()->status);
    }

    public function test_allowlist_restricts_gate_lock_and_unlock_to_its_staff(): void
    {
        $included = $this->row(['reconcile_status' => 'da_chot']);
        $outside = $this->row(['user_id' => $this->admin->id, 'reconcile_status' => 'nhap']);
        $period = $this->calculatedPeriod(['staff_allowlist' => [$this->teacher->id]]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertTrue($included->fresh()->payroll_locked);
        $this->assertFalse($outside->fresh()->payroll_locked);
        $outside->update(['payroll_locked' => true]);
        $this->post(route('payroll.periods.reopen', $period->id))->assertSessionHasNoErrors();
        $this->assertFalse($included->fresh()->payroll_locked);
        $this->assertTrue($outside->fresh()->payroll_locked);
    }

    public function test_deleting_previously_calculated_attendance_requires_recalculation_before_close(): void
    {
        $deleted = $this->row(['reconcile_status' => 'nhap']);
        $this->row(['reconcile_status' => 'da_chot']);
        $period = $this->calculatedPeriod();
        $this->travelTo(now()->addMinute());
        app(TimesheetSoftDelete::class)->softDelete($deleted, $this->admin, 'Sai công đã tính');
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $period->refresh()->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame(1, $period->records()->where('user_id', $this->teacher->id)->firstOrFail()->teaching_sessions);
    }

    public function test_kpi_gate_precedes_attendance_gate(): void
    {
        $this->row(['reconcile_status' => 'nhap']);
        $period = $this->calculatedPeriod();
        $record = $period->records()->where('user_id', $this->teacher->id)->firstOrFail();
        $record->update(['retention_students' => 1, 'kpi_finalized_at' => null]);
        $reason = app(PayrollPeriodLifecycle::class)->blockReason($period, PayrollPeriod::STATUS_CLOSED);
        $this->assertStringContainsString('chưa chốt KPI', $reason);
        $this->actingAs($this->admin)->postJson(route('payroll.periods.approve', $period->id))->assertStatus(302)->assertSessionHasErrors();
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
    }

    public function test_payroll_calculation_counts_only_live_valid_attendance(): void
    {
        $this->row(['bi_xoa_boi_hoc_vu' => true, 'hours' => 9]);
        $this->row(['hours' => 2]);
        $period = $this->calculatedPeriod();
        $record = $period->records()->where('user_id', $this->teacher->id)->firstOrFail();
        $this->assertSame(2.0, (float) $record->actual_hours);
        $this->assertSame(1, $record->teaching_sessions);
        $this->assertSame(600000.0, (float) $record->teaching_salary);
    }
}

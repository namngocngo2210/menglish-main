<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Branch;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lịch chốt lương (chủ dự án chốt): chốt KPI ngày cuối tháng; chốt công + chốt lỗi hết cuối tháng + 2 ngày;
 * trả lương ngày 10–15 tháng sau (chỉ cảnh báo, không chặn).
 */
class PayrollCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'Cơ sở KL', 'code' => 'KL', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole('academic_staff');
    }

    private function september(string $status = 'draft'): PayrollPeriod
    {
        return PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'status' => $status, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
    }

    public function test_period_calendar_dates(): void
    {
        $period = $this->september();

        $this->assertSame('2026-09-30', $period->kpiCloseOn()->toDateString());
        $this->assertSame('2026-10-02 23:59:59', $period->attendanceCloseAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02', $period->violationCloseAt()->toDateString());
        [$from, $to] = $period->payWindow();
        $this->assertSame(['2026-10-10', '2026-10-15'], [$from->toDateString(), $to->toDateString()]);
        $this->assertSame('02/10/2026', $period->calendar()['attendance_close_on']);
    }

    public function test_approve_is_blocked_until_attendance_and_violation_close(): void
    {
        $period = $this->september();

        $this->travelTo(Carbon::parse('2026-10-02 18:00:00'));
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasErrors(['period' => 'Chưa thể chốt bảng lương: chốt công và chốt lỗi vào hết ngày 02/10 (cuối tháng + 2 ngày) — vui lòng chốt từ ngày 03/10.']);
        $this->assertSame('draft', $period->fresh()->status);

        $this->travelTo(Carbon::parse('2026-10-03 08:00:00'));
        $period->calculatePayrollForPeriod();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('approved', $period->fresh()->status);
    }

    public function test_show_page_exposes_calendar(): void
    {
        $period = $this->september();
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period.calendar.kpi_close_on', '30/09/2026')
                ->where('period.calendar.approve_from', '03/10/2026')
                ->where('period.calendar.pay_from', '10/10/2026')
                ->where('period.calendar.pay_to', '15/10/2026')
                ->where('period.calendar.can_approve', false));
    }

    public function test_mark_paid_outside_pay_window_warns_but_is_allowed(): void
    {
        $period = $this->september('approved');

        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        $this->actingAs($this->admin)->post(route('payroll.periods.mark-paid', $period->id))
            ->assertSessionHas('warning')->assertSessionHas('status');
        $this->assertSame('paid', $period->fresh()->status);

        $second = PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'status' => 'approved', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ]);
        $this->travelTo(Carbon::parse('2026-09-12 09:00:00'));
        $this->actingAs($this->admin)->post(route('payroll.periods.mark-paid', $second->id))->assertSessionMissing('warning');
        $this->assertSame('paid', $second->fresh()->status);
    }

    private function scores(): array
    {
        return KpiCriterion::active()->get()->mapWithKeys(fn (KpiCriterion $c) => [$c->id => 100])->all();
    }

    public function test_kpi_confirm_only_from_last_day_of_month(): void
    {
        $payload = ['month' => 9, 'year' => 2026, 'score' => $this->scores()];

        $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
        $this->actingAs($this->admin)->post(route('kpi.evaluate.store', $this->staff->id), $payload)
            ->assertSessionHasErrors(['month' => 'Chốt KPI từ ngày cuối tháng 30/09 — hiện chỉ được lưu nháp.']);
        $this->assertSame(0, KpiEvaluation::count());

        // Lưu nháp thì được
        $this->actingAs($this->admin)->post(route('kpi.evaluate.store', $this->staff->id), $payload + ['action' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame('draft', KpiEvaluation::firstOrFail()->status);

        $this->travelTo(Carbon::parse('2026-09-30 00:30:00'));
        $this->actingAs($this->admin)->post(route('kpi.evaluate.store', $this->staff->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame('confirmed', KpiEvaluation::firstOrFail()->status);
    }

    public function test_calendar_reminders_are_idempotent_and_target_the_right_people(): void
    {
        $period = $this->september();
        $count = fn (string $stage) => AdminNotification::where('type', 'payroll_calendar')->where('data->stage', $stage)->count();

        // Ngày cuối tháng → người chốt KPI
        $this->travelTo(Carbon::parse('2026-09-30 08:35:00'));
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->assertGreaterThan(0, $count('kpi_close'));
        $kpi = $count('kpi_close');
        $this->assertSame(1, AdminNotification::where('type', 'payroll_calendar')->where('data->stage', 'kpi_close')->where('user_id', $this->admin->id)->count());

        // +1 / +2 ngày → người xử lý công / phạt / lương
        $this->travelTo(Carbon::parse('2026-10-01 08:35:00'));
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-10-02 08:35:00'));
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->assertGreaterThan(0, $count('close_d1'));
        $this->assertGreaterThan(0, $count('close_d2'));
        $this->assertSame($kpi, $count('kpi_close'));

        // Ngày 10 → người duyệt / chi lương
        $this->travelTo(Carbon::parse('2026-10-10 08:35:00'));
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->assertSame(1, AdminNotification::where('type', 'payroll_calendar')->where('data->stage', 'pay_window')->where('user_id', $this->admin->id)->count());

        // Kỳ đã trả thì không nhắc nữa
        $period->update(['status' => 'paid']);
        $before = AdminNotification::count();
        $this->artisan('payroll:remind-calendar')->assertSuccessful();
        $this->assertSame($before, AdminNotification::count());
    }
}

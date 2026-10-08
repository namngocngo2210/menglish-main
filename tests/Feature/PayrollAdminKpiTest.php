<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Bảng lương (08/10/2026): Admin không phải chốt KPI — phiếu chỉ hiện % KPI của chính họ nếu có, không báo "Chưa chốt KPI"
 * và không chặn chốt bảng lương. Hợp đồng nhập sau lần tính làm kỳ báo phải "Đồng bộ & Tính lại"; hợp đồng bắt đầu sau
 * kỳ thì kỳ đó không có phiếu.
 */
class PayrollAdminKpiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Lương', 'code' => 'LG', 'is_active' => true]);
        $this->admin = $this->staff(Roles::ADMIN, 'Quản trị hệ thống', 20000000);

        $start = Carbon::create(2026, 9, 1);
        $this->period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'draft',
        ]);
    }

    private function staff(string $role, string $name, int $baseSalary, array $attributes = []): User
    {
        $user = User::factory()->create([
            'name' => $name, 'branch_id' => $this->branch->id, 'is_active' => true, 'base_salary' => $baseSalary,
            'created_at' => '2026-08-01 08:00:00', ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function recordOf(User $user): ?PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $this->period->id)->where('user_id', $user->id)->first();
    }

    public function test_admin_payslip_has_no_kpi_to_close_and_does_not_block_approval(): void
    {
        $manager = $this->staff(Roles::MANAGER, 'QLCS Một', 10000000);
        $this->period->calculatePayrollForPeriod();

        $record = $this->recordOf($this->admin);
        $this->assertSame(PayrollRecord::KPI_SELF, $record->kpi_source);
        $this->assertSame(['self', ''], $record->kpi_state);
        $this->assertSame(0.0, (float) $record->kpi_bonus);
        $this->assertNotContains('kpi_bonus', array_column($record->earningLines(), 'key'));
        // Nhân sự khác vẫn phải chốt KPI như cũ.
        $this->assertSame('pending', $this->recordOf($manager)->kpi_state[0]);

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $this->period->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpiPending.count', 1)
                ->where('kpiPending.names', 'QLCS Một'));
    }

    public function test_admin_sees_own_confirmed_kpi_percent_without_kpi_money(): void
    {
        KpiEvaluation::create([
            'user_id' => $this->admin->id, 'month' => 9, 'year' => 2026, 'track' => KpiEvaluation::TRACK_MAIN,
            'period_months' => 1, 'total_score' => 87.5, 'status' => KpiEvaluation::STATUS_APPROVED,
        ]);
        $this->period->calculatePayrollForPeriod();

        $record = $this->recordOf($this->admin);
        $this->assertSame(87.5, (float) $record->kpi_score);
        $this->assertSame(['self', 'KPI 87,5%'], $record->kpi_state);
        $this->assertSame(0.0, (float) $record->kpi_bonus);
        $line = collect($record->earningLines())->firstWhere('key', 'kpi_bonus');
        $this->assertSame(0.0, $line['amount']);
        $this->assertStringContainsString('87,5%', $line['hint']);
    }

    public function test_admin_draft_kpi_is_not_shown(): void
    {
        KpiEvaluation::create([
            'user_id' => $this->admin->id, 'month' => 9, 'year' => 2026, 'track' => KpiEvaluation::TRACK_MAIN,
            'period_months' => 1, 'total_score' => 60, 'status' => KpiEvaluation::STATUS_PENDING,
        ]);
        $this->period->calculatePayrollForPeriod();

        $this->assertNull($this->recordOf($this->admin)->kpi_score);
        $this->assertSame(['self', ''], $this->recordOf($this->admin)->kpi_state);
    }

    public function test_contract_entered_after_calculation_asks_for_recalculation(): void
    {
        $manager = $this->staff(Roles::MANAGER, 'QLCS 1', 0);
        $this->period->calculatePayrollForPeriod();
        $this->assertNull($this->recordOf($manager));
        $this->assertFalse($this->period->fresh()->hasChangesSinceCalculation());

        // Nhập hợp đồng Full-time 10.000.000đ từ 01/08 sau lần tính.
        $manager->update(['contract_type' => 'Toàn thời gian', 'base_salary' => 10000000, 'contract_start_date' => '2026-08-01']);
        $this->assertTrue($this->period->fresh()->hasChangesSinceCalculation());

        $this->period->fresh()->calculatePayrollForPeriod();
        $this->assertSame(10000000.0, (float) $this->recordOf($manager)->base_salary);
        $this->assertFalse($this->period->fresh()->hasChangesSinceCalculation());
    }

    public function test_base_salary_raised_after_calculation_asks_for_recalculation(): void
    {
        $manager = $this->staff(Roles::MANAGER, 'QLCS 1', 8000000);
        $this->period->calculatePayrollForPeriod();
        $this->assertFalse($this->period->fresh()->hasChangesSinceCalculation());

        $manager->update(['base_salary' => 10000000]);
        $this->assertTrue($this->period->fresh()->hasChangesSinceCalculation());
    }

    public function test_contract_starting_after_period_gets_no_payslip(): void
    {
        $newcomer = $this->staff(Roles::MANAGER, 'QLCS Mới', 10000000, ['contract_start_date' => '2026-10-01']);
        $this->period->calculatePayrollForPeriod();

        $this->assertNull($this->recordOf($newcomer));
        $this->assertFalse($this->period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $this->period->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('missingStaff.count', 0));
    }
}

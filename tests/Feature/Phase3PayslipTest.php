<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiCriterion;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — Phiếu lương từng người (xem, điều chỉnh tay khi kỳ chưa duyệt),
 * "Lương của tôi" chỉ kỳ đã duyệt, không tự chấm KPI của mình.
 */
class Phase3PayslipTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $accountant;

    private User $manager;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở PL', 'code' => 'PL', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->accountant = $this->userWithRole('accountant');
        $this->manager = $this->userWithRole('manager');
        $this->teacher = $this->userWithRole('teacher', ['name' => 'GV Phiếu Lương', 'base_salary' => 8000000]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function calculatedPeriod(): PayrollPeriod
    {
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-08', 'title' => 'Bảng lương Tháng 8/2026', 'month' => 8, 'year' => 2026,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();

        return $period;
    }

    private function record(PayrollPeriod $period): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->teacher->id)->firstOrFail();
    }

    public function test_payslip_shows_all_lines_and_is_linked_from_period_tables(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->record($period);

        $this->actingAs($this->accountant)->get(route('payroll.periods.show', $period->id))
            ->assertOk()
            ->assertSee(route('payroll.records.show', $record->id), false);

        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()
            ->assertSee('Phiếu lương: GV Phiếu Lương')
            ->assertSee('Lương cơ bản / cứng')
            ->assertSee('Hoa hồng tuyển sinh (khách mới)')
            ->assertSee('Thu hồi hoa hồng (hoàn phí)')
            ->assertSee('Phạt vi phạm (quá hạn nộp)')
            ->assertSee('Khấu trừ khác')
            ->assertSee('7.660.000') // 8M + 500k phụ cấp − 840k BHXH
            ->assertSee('Lưu điều chỉnh');

        // Nhân viên thường không vào được màn phiếu lương quản trị
        $this->actingAs($this->teacher)->get(route('payroll.records.show', $record->id))->assertForbidden();
    }

    public function test_accountant_adjusts_manual_items_and_they_survive_recalculation(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->record($period);

        // Có khoản cộng/trừ khác thì bắt buộc ghi chú
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), ['other_bonus' => 300000])
            ->assertSessionHasErrors('adjustment_notes');

        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), [
            'allowance_override' => 700000, 'other_bonus' => 300000, 'other_deduction' => 100000,
            'adjustment_notes' => 'Thưởng lễ 2/9, trừ tạm ứng',
        ])->assertRedirect(route('payroll.records.show', $record->id))->assertSessionHasNoErrors();

        $record->refresh();
        // 8M + 700k + 300k − 840k − 100k
        $this->assertEquals(8060000, $record->net_salary);
        $this->assertEquals(8060000, $period->fresh()->total_amount);

        $period->calculatePayrollForPeriod();
        $record->refresh();
        $this->assertEquals(700000, $record->allowance);
        $this->assertEquals(300000, $record->other_bonus);
        $this->assertEquals(100000, $record->other_deduction);
        $this->assertSame('Thưởng lễ 2/9, trừ tạm ứng', $record->adjustment_notes);
        $this->assertEquals(8060000, $record->net_salary);

        // Bỏ điều chỉnh phụ cấp → về mức cấu hình
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), [
            'other_bonus' => 0, 'other_deduction' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(500000, $record->fresh()->allowance);
        $this->assertEquals(7660000, $record->fresh()->net_salary);
    }

    public function test_adjustment_is_blocked_for_non_editors_and_locked_periods(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->record($period);

        $this->actingAs($this->manager)->post(route('payroll.records.adjust', $record->id), ['other_bonus' => 1])->assertForbidden();

        $period->update(['status' => 'approved']);
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), ['other_bonus' => 100000, 'adjustment_notes' => 'x'])
            ->assertStatus(422);
        $this->actingAs($this->accountant)->get(route('payroll.records.show', $record->id))
            ->assertOk()
            ->assertDontSee('Lưu điều chỉnh');
    }

    public function test_my_salary_lists_only_approved_periods_with_same_lines(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->record($period);
        $record->update(['other_bonus' => 250000, 'adjustment_notes' => 'Thưởng nóng']);

        $this->actingAs($this->teacher)->get(route('portal.my-salary'))
            ->assertOk()
            ->assertDontSee('Thưởng nóng');

        $period->update(['status' => 'approved']);
        $this->actingAs($this->teacher)->get(route('portal.my-salary'))
            ->assertOk()
            ->assertSee('Thưởng / cộng khác')
            ->assertSee('250,000')
            ->assertSee('Thưởng nóng');
    }

    public function test_staff_cannot_score_own_kpi(): void
    {
        $lead = $this->userWithRole('academic_lead');
        $criterion = KpiCriterion::create(['name' => 'Chuyên cần', 'weight' => 1, 'is_active' => true]);
        $payload = ['month' => 9, 'year' => 2026, 'score' => [$criterion->id => 90]];

        $this->actingAs($lead)->post(route('kpi.evaluate.store', $lead->id), $payload)->assertForbidden();
        $this->assertDatabaseCount('kpi_evaluations', 0);

        $this->actingAs($lead)->get(route('kpi.evaluate', $lead->id))
            ->assertOk()
            ->assertSee('Không tự chấm KPI')
            ->assertDontSee('Lưu đánh giá');

        $this->actingAs($lead)->post(route('kpi.evaluate.store', $this->teacher->id), $payload)->assertRedirect();
        $this->assertDatabaseCount('kpi_evaluations', 1);
    }
}

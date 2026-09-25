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
            // Q3: GV có lương cơ bản → Full-time
            ->assertSee('Full-time')
            ->assertSee('Lương cơ bản')
            ->assertSee('BHXH (10,5% lương cơ bản)')
            ->assertSee('Công đoàn (0,5% lương cơ bản)')
            ->assertSee('Thuế TNCN')
            ->assertSee('Phạt vi phạm (quá hạn nộp)')
            ->assertSee('7.120.000') // 8M − 840k BHXH − 40k Công đoàn
            ->assertSee('Lưu điều chỉnh');

        // Nhân viên thường không vào được màn phiếu lương quản trị
        $this->actingAs($this->teacher)->get(route('payroll.records.show', $record->id))->assertForbidden();
    }

    public function test_accountant_adjusts_manual_items_and_they_survive_recalculation(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->record($period);

        // Khoản cộng/trừ tự do phải có tên
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), ['lines' => [['kind' => 'earning', 'label' => '', 'amount' => 300000]]])
            ->assertSessionHasErrors('lines');

        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), [
            'lines' => [
                ['kind' => 'earning', 'label' => 'Phụ cấp', 'amount' => 700000],
                ['kind' => 'earning', 'label' => 'Thưởng lễ 2/9', 'amount' => 300000],
                ['kind' => 'deduction', 'label' => 'Trừ tạm ứng', 'amount' => 100000],
            ],
            'adjustment_notes' => 'Thưởng lễ 2/9, trừ tạm ứng',
        ])->assertRedirect(route('payroll.records.show', $record->id))->assertSessionHasNoErrors();

        $record->refresh();
        // 8M + 700k + 300k − 840k − 40k − 100k
        $this->assertEquals(8020000, $record->net_salary);
        $this->assertEquals(8020000, $period->fresh()->total_amount);

        $period->calculatePayrollForPeriod();
        $record->refresh();
        $this->assertEquals(1000000, $record->allowance);
        $this->assertEquals(100000, $record->other_deduction);
        $this->assertSame('Thưởng lễ 2/9, trừ tạm ứng', $record->adjustment_notes);
        $this->assertEquals(8020000, $record->net_salary);

        // Bỏ các dòng tự do → về lương theo công thức
        $this->actingAs($this->accountant)->post(route('payroll.records.adjust', $record->id), ['lines' => []])->assertSessionHasNoErrors();
        $this->assertEquals(0, $record->fresh()->allowance);
        $this->assertEquals(7120000, $record->fresh()->net_salary);
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

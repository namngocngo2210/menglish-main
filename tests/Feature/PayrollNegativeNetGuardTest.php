<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodLifecycle;
use App\Support\Money;
use App\Support\Rbac;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

/** TK-0017: giữ thực lĩnh tối thiểu 0 nhưng không chốt kỳ có khấu trừ vượt thu nhập. */
class PayrollNegativeNetGuardTest extends TestCase
{
    use FinalizesPayrollKpi;
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
        $branch = Branch::create(['code' => 'TK17', 'name' => 'Cơ sở thực lĩnh âm', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create([
            'name' => 'Nguyễn Văn A', 'branch_id' => $branch->id, 'is_active' => true, 'base_salary' => 0,
        ]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->classModel = ClassModel::create([
            'code' => 'TK17-01', 'name' => 'Lớp thực lĩnh âm', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function calculatedPeriod(float $hourlyRate = 300000): PayrollPeriod
    {
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-09-15', 'hours' => 2, 'hourly_rate' => $hourlyRate,
            'type' => 'regular', 'status' => 'valid',
        ]);
        $period = PayrollPeriod::create([
            'code' => 'PR-TK17-09', 'title' => 'Bảng lương TK-0017 tháng 9',
            'month' => 9, 'year' => 2026, 'start_date' => '2026-09-01',
            'end_date' => '2026-09-30', 'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
        $period->calculatePayrollForPeriod();
        $this->finalizeKpi($period);

        return $period->fresh();
    }

    private function negativeRecord(PayrollPeriod $period): PayrollRecord
    {
        $record = $period->records()->where('user_id', $this->teacher->id)->firstOrFail();
        $record->manual_lines = [
            ['kind' => 'deduction', 'label' => 'Khấu trừ đối soát', 'amount' => $record->gross_income + 1250000],
        ];
        $record->applyManualInputs();
        $record->save();

        return $record->fresh();
    }

    public function test_raw_net_and_shortfall_follow_record_columns_while_payout_stays_zero(): void
    {
        $record = new PayrollRecord([
            'base_salary' => 1000000, 'penalty_deduction' => 1500000,
            'insurance_deduction' => 100000, 'commission_clawback' => 650000,
        ]);
        $record->calculateNetSalary();
        $this->assertSame(-1250000.0, $record->rawNetSalary());
        $this->assertSame(1250000.0, $record->netShortfall());
        $this->assertSame(0.0, (float) $record->net_salary);

        $record->other_bonus = 1250000;
        $record->calculateNetSalary();
        $this->assertSame(0.0, $record->rawNetSalary());
        $this->assertSame(0.0, $record->netShortfall());
        $record->other_bonus = 1250000.01;
        $record->calculateNetSalary();
        $this->assertSame(0.01, $record->rawNetSalary());
        $this->assertSame(0.0, $record->netShortfall());
        $this->assertSame(0.01, (float) $record->net_salary);
    }

    public function test_approve_refuses_all_negative_records_sorted_by_name_without_transition(): void
    {
        $period = $this->calculatedPeriod();
        $this->teacher->update(['name' => 'Trần Thị B']);
        $this->negativeRecord($period);
        $other = User::factory()->create(['name' => 'Nguyễn Văn A', 'base_salary' => 0]);
        PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $other->id,
            'salary_role' => Roles::TEACHER_PARTTIME, 'employee_type' => PayrollRecord::TYPE_PARTTIME,
            'other_deduction' => 300000, 'net_salary' => 0,
        ]);
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertRedirect()->assertSessionHasErrors('period')
            ->assertSessionHas('errors', function ($errors) use ($other) {
                $reason = $errors->first('period');
                $this->assertStringContainsString('2 nhân sự', $reason);
                $this->assertStringContainsString($this->teacher->name.' – thiếu '.Money::format(1250000), $reason);
                $this->assertStringContainsString($other->name.' – thiếu '.Money::format(300000), $reason);
                $this->assertLessThan(strpos($reason, $this->teacher->name), strpos($reason, $other->name));

                return true;
            });
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $this->assertSame(0, $period->transitions()->count());
    }

    public function test_calculation_keeps_assistant_penalties_when_net_is_clamped_to_zero(): void
    {
        $this->teacher->syncRoles([Roles::ASSISTANT]);
        Penalty::create([
            'code' => 'BB-TK17-TA', 'user_id' => $this->teacher->id, 'violation_type' => 'Quên điểm danh',
            'violation_date' => '2026-09-10', 'amount' => 1320000, 'status' => 'fined',
        ]);
        $period = $this->calculatedPeriod(35000);
        $record = $period->records()->where('user_id', $this->teacher->id)->firstOrFail();
        $this->assertTrue($record->isAssistant());
        $this->assertSame(70000.0, (float) $record->teaching_salary);
        $this->assertSame(1320000.0, (float) $record->penalty_deduction);
        $this->assertSame(-1250000.0, $record->rawNetSalary());
        $this->assertSame(1250000.0, $record->netShortfall());
        $this->assertSame(0.0, (float) $record->net_salary);
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))
            ->assertOk()->assertSee('Khấu trừ vượt thu nhập: thiếu '.Money::format(1250000));
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
    }

    public function test_actions_and_period_page_show_the_shortfall_and_disable_approve(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->negativeRecord($period);
        // Không dựa vào relation đã nạp (có thể bị giới hạn theo phạm vi người xem).
        $period->setRelation('records', collect());
        $action = app(PayrollPeriodLifecycle::class)->actions($period, $this->admin)[0];
        $this->assertSame('approve', $action['key']);
        $this->assertFalse($action['enabled']);
        $this->assertStringContainsString($this->teacher->name.' – thiếu '.Money::format(1250000), $action['reason']);

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertOk()->assertSee('Khấu trừ vượt thu nhập')
            ->assertSee('Thiếu '.Money::format(1250000))
            ->assertSee('data-negative-net', false)
            ->assertSee('class="bg-error-container"', false)
            ->assertSee('href="'.route('payroll.records.show', $record->id, false).'"', false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payroll/Show')
                ->where('period.actions.0.enabled', false)->where('period.actions.0.reason', $action['reason'])
                ->has('negativeNet', 1)->where('negativeNet.0.id', $record->id)
                ->where('negativeNet.0.name', $this->teacher->name)
                ->where('negativeNet.0.shortfall', 1250000)->where('negativeNet.0.raw_net', -1250000)
                ->where('records.data.0.shortfall', 1250000)->where('records.data.0.raw_net', -1250000));
    }

    public function test_warning_remains_visible_in_every_status_even_when_table_is_filtered(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->negativeRecord($period);
        foreach (array_keys(PayrollPeriod::STATUS_LABELS) as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->admin)->get(route('payroll.periods.show', ['id' => $period->id, 'search' => 'Không khớp']))
                ->assertOk()->assertSee('Khấu trừ vượt thu nhập')->assertSee(Money::format(1250000))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('records.total', 0)->has('negativeNet', 1)->where('negativeNet.0.id', $record->id));
        }
    }

    public function test_negative_net_banner_only_lists_records_in_viewer_scope(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->negativeRecord($period);
        PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->admin->id,
            'salary_role' => Roles::ADMIN, 'other_deduction' => 300000, 'net_salary' => 0,
        ]);
        $period->update(['status' => PayrollPeriod::STATUS_PUBLISHED]);
        $this->teacher->givePermissionTo(['payroll.view', 'payroll.scope_own']);
        Rbac::flushCache();
        $this->actingAs($this->teacher->fresh())->get(route('payroll.periods.show', $period->id))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->has('negativeNet', 1)->where('negativeNet.0.id', $record->id));

        // Người chốt có phạm vi hẹp vẫn phải kiểm tra mọi phiếu, kể cả phiếu không hiện trong bảng.
        $period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        $this->teacher->givePermissionTo('payroll.approve');
        Rbac::flushCache();
        $this->actingAs($this->teacher->fresh())->get(route('payroll.periods.show', $period->id))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('negativeNet', [])->where('records.total', 0)
                ->where('period.actions.0.enabled', false)
                ->where('period.actions.0.reason', fn ($reason) => str_contains($reason, $this->admin->name.' – thiếu '.Money::format(300000))));
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $this->assertSame(0, $period->transitions()->count());
    }

    public function test_freshness_check_precedes_shortfall_guard(): void
    {
        $period = $this->calculatedPeriod();
        $this->negativeRecord($period);
        $this->travelTo(now()->addMinute());
        TeacherTimesheet::where('user_id', $this->teacher->id)->update(['hours' => 3, 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasErrors(['period' => 'Chấm công hoặc biên bản phạt của kỳ đã thay đổi sau lần tính gần nhất — vui lòng bấm "Đồng bộ & Tính lại" trước khi duyệt.']);
        $this->assertSame(0, $period->transitions()->count());
    }

    public function test_manual_earning_fix_survives_recalculation_and_allows_approve_at_zero_net(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->negativeRecord($period);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $record->manual_lines = [
            ...$record->manualLines(),
            ['kind' => 'earning', 'label' => 'Điều chỉnh đối soát', 'amount' => 1250000],
        ];
        $record->applyManualInputs();
        $record->save();
        $this->travelTo(now()->addMinute());
        $period->refresh()->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->assertSame(0.0, $record->fresh()->rawNetSalary());
        $this->assertSame(0.0, $record->fresh()->netShortfall());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PayrollPeriod::STATUS_CLOSED, $period->fresh()->status);
        $this->assertSame(1, $period->transitions()->count());
        $this->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('negativeNet', []));
    }

    public function test_payslip_shows_shortfall_to_its_existing_viewers(): void
    {
        $period = $this->calculatedPeriod();
        $record = $this->negativeRecord($period);
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))
            ->assertOk()->assertSee('Khấu trừ vượt thu nhập: thiếu '.Money::format(1250000).' — thực lĩnh tạm tính 0 đ')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payroll/Record')->where('record.shortfall', 1250000)
                ->where('record.raw_net', -1250000)->where('record.net_salary', 0));
        $period->update(['status' => PayrollPeriod::STATUS_PUBLISHED]);
        $this->teacher->givePermissionTo(['payroll.view', 'payroll.scope_own']);
        Rbac::flushCache();
        $this->actingAs($this->teacher->fresh())->get(route('payroll.records.show', $record->id))
            ->assertOk()->assertSee('Khấu trừ vượt thu nhập: thiếu '.Money::format(1250000));
    }
}

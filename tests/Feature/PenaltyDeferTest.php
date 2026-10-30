<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Rbac;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

/** Chuyển kỳ trừ phạt giữ nguyên biên bản và buộc tính lại các phiếu bị ảnh hưởng. */
class PenaltyDeferTest extends TestCase
{
    use FinalizesPayrollKpi;
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
        $branch = Branch::create(['code' => 'DEFER', 'name' => 'Cơ sở chuyển phạt', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create([
            'name' => 'Nhân sự chuyển phạt', 'branch_id' => $branch->id, 'is_active' => true, 'base_salary' => 0,
        ]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $class = ClassModel::create([
            'code' => 'DEFER-01', 'name' => 'Lớp chuyển phạt', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
        foreach ([9, 10, 11] as $month) {
            TeacherTimesheet::create([
                'user_id' => $this->teacher->id, 'class_id' => $class->id,
                'teaching_date' => sprintf('2026-%02d-15', $month), 'hours' => 2, 'hourly_rate' => 35000,
                'type' => 'regular', 'status' => 'valid',
            ]);
        }
    }

    private function period(int $month = 9, string $status = PayrollPeriod::STATUS_DRAFT): PayrollPeriod
    {
        $start = Carbon::create(2026, $month, 1);

        return PayrollPeriod::create([
            'code' => "PR-DEFER-{$month}", 'title' => "Bảng lương tháng {$month}/2026",
            'month' => $month, 'year' => 2026, 'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => $status,
        ]);
    }

    private function penalty(array $attributes = []): Penalty
    {
        return Penalty::create($attributes + [
            'code' => 'BB-DEFER-'.(Penalty::withoutViewerScope()->count() + 1), 'user_id' => $this->teacher->id,
            'violation_type' => 'Quên điểm danh', 'error_category' => 'operations',
            'violation_date' => '2026-09-10', 'amount' => 1320000, 'status' => 'fined',
            'due_date' => '2026-09-12', 'notes' => 'Biên bản hợp lệ',
        ]);
    }

    private function record(PayrollPeriod $period): PayrollRecord
    {
        return $period->records()->where('user_id', $this->teacher->id)->firstOrFail();
    }

    public function test_deferral_detaches_source_marks_both_periods_stale_and_deducts_only_in_target(): void
    {
        $source = $this->period();
        $target = $this->period(10);
        $penalty = $this->penalty();
        $source->calculatePayrollForPeriod();
        $target->calculatePayrollForPeriod();
        $this->assertSame($this->record($source)->id, $penalty->fresh()->payroll_record_id);
        $this->assertFalse($source->fresh()->hasChangesSinceCalculation());
        $this->assertFalse($target->fresh()->hasChangesSinceCalculation());

        // Cùng giây với lần tính: không chỉ dựa vào updated_at > calculated_at để báo thay đổi.
        $this->actingAs($this->admin)->from(route('payroll.periods.show', $source->id))
            ->post(route('penalties.defer', $penalty->id), ['reason' => 'Thỏa thuận trừ kỳ sau'])
            ->assertRedirect(route('payroll.periods.show', $source->id))->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, $target->title) && str_contains($message, 'Đồng bộ & Tính lại'));
        $penalty->refresh();
        $this->assertSame('2026-10-01', $penalty->deduct_from_date->toDateString());
        $this->assertSame($this->admin->id, $penalty->deferred_by);
        $this->assertTrue($penalty->deferred_at->equalTo(now()));
        $this->assertSame('Thỏa thuận trừ kỳ sau', $penalty->defer_reason);
        $this->assertSame($this->admin->id, $penalty->deferredBy->id);
        $this->assertSame('2026-09-10', $penalty->violation_date->toDateString());
        $this->assertSame('fined', $penalty->status);
        $this->assertNull($penalty->payroll_record_id);
        $this->assertStringContainsString('Biên bản hợp lệ', $penalty->notes);
        $this->assertStringContainsString('Chuyển trừ sang kỳ '.$target->title, $penalty->notes);
        $this->assertTrue($source->fresh()->hasChangesSinceCalculation());
        $this->assertTrue($target->fresh()->hasChangesSinceCalculation());
        $this->assertFalse(Penalty::deductibleFor($source)->whereKey($penalty->id)->exists());
        $this->assertTrue(Penalty::deductibleFor($target)->whereKey($penalty->id)->exists());

        $source->refresh()->calculatePayrollForPeriod();
        $this->assertSame(0.0, (float) $this->record($source)->penalty_deduction);
        $this->assertFalse($source->fresh()->hasChangesSinceCalculation());
        $target->refresh()->calculatePayrollForPeriod();
        $this->assertSame(1320000.0, (float) $this->record($target)->penalty_deduction);
        $this->assertSame($this->record($target)->id, $penalty->fresh()->payroll_record_id);
        $this->assertFalse($target->fresh()->hasChangesSinceCalculation());
    }

    public function test_unattached_penalty_can_be_deferred_before_target_exists_and_deferred_again(): void
    {
        $source = $this->period();
        $penalty = $this->penalty();
        $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển lần đầu'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'bắt đầu 01/10/2026'));
        $this->assertSame('2026-10-01', $penalty->fresh()->deduct_from_date->toDateString());
        $target = $this->period(10);
        $later = $this->period(11);
        // Kỳ 9 còn mở cũng không được giữ khoản phạt đã chuyển; kỳ 11 không được lấy trước kỳ 10.
        $this->assertFalse(Penalty::deductibleFor($source)->whereKey($penalty->id)->exists());
        $this->assertFalse(Penalty::deductibleFor($later)->whereKey($penalty->id)->exists());
        $this->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển lần hai'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', $penalty->fresh()->deduct_from_date->toDateString());
        $this->assertFalse(Penalty::deductibleFor($target)->whereKey($penalty->id)->exists());
        $this->assertTrue(Penalty::deductibleFor($later)->whereKey($penalty->id)->exists());
        $this->assertStringContainsString('Chuyển lần đầu', $penalty->fresh()->notes);
        $this->assertStringContainsString('Chuyển lần hai', $penalty->fresh()->notes);
    }

    public function test_effective_date_with_time_uses_calendar_dates_for_range_and_carry(): void
    {
        $source = $this->period();
        $target = $this->period(10);
        $later = $this->period(11);
        $penalty = $this->penalty(['violation_date' => '2026-08-31']);
        DB::table('penalties')->where('id', $penalty->id)->update(['deduct_from_date' => '2026-09-30 18:00:00']);
        $this->assertTrue(Penalty::deductibleFor($source)->whereKey($penalty->id)->exists());
        $this->assertFalse(Penalty::deductibleFor($target)->whereKey($penalty->id)->exists());
        $source->update(['status' => PayrollPeriod::STATUS_PUBLISHED]);
        $this->assertTrue(Penalty::deductibleFor($target)->whereKey($penalty->id)->exists());
        $this->assertFalse(Penalty::deductibleFor($later)->whereKey($penalty->id)->exists());
    }

    public function test_non_fined_statuses_are_refused_without_mutation(): void
    {
        $this->period();
        foreach (['paid', 'cancelled', 'deducted', 'pending', 'explained', 'confirmed', 'resolved'] as $status) {
            $penalty = $this->penalty(['status' => $status]);
            $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])
                ->assertRedirect()->assertSessionHasErrors('penalty')->assertSessionHas('error');
            $this->assertSame($status, $penalty->fresh()->status);
            $this->assertNull($penalty->fresh()->deduct_from_date);
        }
    }

    public function test_attached_frozen_source_periods_are_refused(): void
    {
        $source = $this->period();
        $penalty = $this->penalty();
        $source->calculatePayrollForPeriod();
        $recordId = $penalty->fresh()->payroll_record_id;
        foreach (PayrollPeriod::FROZEN_STATUSES as $status) {
            $source->update(['status' => $status]);
            $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])
                ->assertSessionHasErrors('penalty')->assertSessionHas('error', fn ($message) => $status !== PayrollPeriod::STATUS_CLOSED || str_contains($message, 'Mở lại'));
            $this->assertNull($penalty->fresh()->deduct_from_date);
            $this->assertSame($recordId, $penalty->fresh()->payroll_record_id);
        }
    }

    public function test_unattached_penalty_in_closed_source_requires_reopening(): void
    {
        $this->period(9, PayrollPeriod::STATUS_CLOSED);
        $penalty = $this->penalty();
        $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])
            ->assertSessionHasErrors('penalty')->assertSessionHas('error', fn ($message) => str_contains($message, 'Mở lại'));
        $this->assertNull($penalty->fresh()->deduct_from_date);
    }

    public function test_frozen_target_periods_are_refused(): void
    {
        $this->period();
        $target = $this->period(10);
        $penalty = $this->penalty();
        foreach (PayrollPeriod::FROZEN_STATUSES as $status) {
            $target->update(['status' => $status]);
            $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])
                ->assertSessionHasErrors(['penalty' => 'Kỳ lương '.$target->title.' đã chốt — không thể chuyển khoản phạt vào kỳ này.']);
            $this->assertNull($penalty->fresh()->deduct_from_date);
        }
    }

    public function test_missing_source_is_refused(): void
    {
        $penalty = $this->penalty();
        $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])
            ->assertSessionHasErrors(['penalty' => 'Biên bản chưa thuộc kỳ lương đang tính nào']);
        $this->assertNull($penalty->fresh()->deduct_from_date);
    }

    public function test_permission_is_required_and_payroll_editor_is_not_limited_by_violation_scope(): void
    {
        $this->period();
        $penalty = $this->penalty();
        $editor = User::factory()->create(['is_active' => true]);
        $this->actingAs($editor)->post(route('penalties.defer', $penalty->id), ['reason' => 'Chuyển kỳ'])->assertForbidden();
        $editor->givePermissionTo('payroll.edit');
        Rbac::flushCache();
        $this->actingAs($editor->fresh())->post(route('penalties.defer', $penalty->id), ['reason' => 'Kế toán đối soát'])->assertSessionHasNoErrors();
        $this->assertSame($editor->id, $penalty->fresh()->deferred_by);
    }

    public function test_reason_is_required_and_limited_to_500_characters(): void
    {
        $this->period();
        $penalty = $this->penalty();
        foreach ([[], ['reason' => ''], ['reason' => str_repeat('a', 501)]] as $payload) {
            $this->actingAs($this->admin)->post(route('penalties.defer', $penalty->id), $payload)->assertSessionHasErrors('reason');
            $this->assertNull($penalty->fresh()->deduct_from_date);
        }
    }

    public function test_assistant_can_close_source_after_deferring_and_recalculating_then_target_deducts(): void
    {
        $this->teacher->syncRoles([Roles::ASSISTANT]);
        $penalty = $this->penalty();
        $source = $this->period();
        $target = $this->period(10);
        $source->calculatePayrollForPeriod();
        $this->finalizeKpi($source);
        $record = $this->record($source);
        $this->assertTrue($record->isAssistant());
        $this->assertSame(70000.0, (float) $record->teaching_salary);
        $this->assertSame(1250000.0, $record->netShortfall());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $source->id))->assertSessionHasErrors('period');
        $this->get(route('payroll.periods.show', $source->id))->assertOk()
            ->assertSee('Chuyển sang kỳ sau')->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period.next_step', 'Còn 1 nhân sự thực lĩnh âm — chuyển phạt sang kỳ sau hoặc điều chỉnh phiếu trước khi chốt')
                ->where('period.next_color', 'error')
                ->has('negativeNet.0.penalties', 1)->where('negativeNet.0.penalties.0.id', $penalty->id)
                ->where('negativeNet.0.penalties.0.violation_date', '2026-09-10'));
        $this->get(route('payroll.records.show', $record->id))->assertOk()->assertSee('Chuyển sang kỳ sau')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('penalties.0.can_defer', true));
        $this->post(route('penalties.defer', $penalty->id), ['reason' => 'Trợ giảng chưa đủ thu nhập'])->assertSessionHasNoErrors();
        $this->post(route('payroll.periods.approve', $source->id))->assertSessionHasErrors('period');
        $source->refresh()->calculatePayrollForPeriod();
        $this->finalizeKpi($source);
        $this->assertSame(0.0, $this->record($source)->netShortfall());
        $this->post(route('payroll.periods.approve', $source->id))->assertSessionHasNoErrors();
        $this->assertSame(PayrollPeriod::STATUS_CLOSED, $source->fresh()->status);
        $this->get(route('penalties.index'))->assertOk()->assertSee('Trừ từ kỳ bắt đầu 01/10/2026')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('penalties.data.0.deduct_from_date', '2026-10-01')
                ->where('penalties.data.0.deferred_by', $this->admin->name)
                ->where('penalties.data.0.defer_reason', 'Trợ giảng chưa đủ thu nhập'));
        $target->refresh()->calculatePayrollForPeriod();
        $this->assertSame($this->record($target)->id, $penalty->fresh()->payroll_record_id);
        $this->assertSame(1320000.0, (float) $this->record($target)->penalty_deduction);
    }

    public function test_next_step_uses_all_period_records_even_when_viewer_sees_no_records(): void
    {
        $this->teacher->syncRoles([Roles::ASSISTANT]);
        $this->penalty();
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(['payroll.view', 'payroll.scope_own']);
        Rbac::flushCache();
        $this->actingAs($viewer)->get(route('payroll.periods.show', $period->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('records.total', 0)->where('negativeNet', [])
                ->where('period.next_step', 'Chờ Kế toán xử lý 1 nhân sự thực lĩnh âm')
                ->where('period.next_color', 'error'));
    }
}

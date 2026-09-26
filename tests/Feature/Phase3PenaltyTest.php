<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

/**
 * Phase 3 — Kỷ luật: ghi nhận → giải trình → HT/CM chốt theo loại lỗi →
 * nộp trong 2 ngày → quá hạn chưa nộp thì trừ vào kỳ lương.
 */
class Phase3PenaltyTest extends TestCase
{
    use FinalizesPayrollKpi;
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $academicLead;

    private User $academicStaff;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở KL', 'code' => 'KL', 'is_active' => true]);
        $this->admin = $this->userWithRole('admin');
        $this->manager = $this->userWithRole('manager');
        $this->academicLead = $this->userWithRole('academic_lead');
        $this->academicStaff = $this->userWithRole('academic_staff');
        $this->teacher = $this->userWithRole('teacher', ['name' => 'GV Vi Phạm', 'hourly_rate' => 300000]);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function record(string $category = 'academic', string $date = '2026-09-10'): Penalty
    {
        $this->actingAs($this->academicStaff)->post(route('penalties.store'), [
            'user_id' => $this->teacher->id,
            'error_category' => $category,
            'violation_type' => 'Chậm nộp nhận xét buổi học (> 24h)',
            'violation_date' => $date,
        ])->assertRedirect(route('penalties.index'))->assertSessionHasNoErrors();

        return Penalty::latest('id')->firstOrFail();
    }

    private function period(int $month, int $year = 2026, string $status = 'draft'): PayrollPeriod
    {
        $start = Carbon::create($year, $month, 1);

        return PayrollPeriod::create([
            'code' => sprintf('PR-%d-%02d', $year, $month), 'title' => "Bảng lương Tháng {$month}/{$year}",
            'month' => $month, 'year' => $year, 'status' => $status,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(),
        ]);
    }

    public function test_recording_a_violation_does_not_require_amount(): void
    {
        $penalty = $this->record();

        $this->assertSame('pending', $penalty->status);
        $this->assertEquals(0, $penalty->amount);
        $this->assertSame('academic', $penalty->error_category);
        $this->assertSame('Chờ giải trình', $penalty->status_label);

        $this->actingAs($this->academicStaff)->get(route('penalties.index'))
            ->assertOk()
            ->assertDontSee('value="200000"', false);
    }

    public function test_only_the_violator_can_explain_while_pending(): void
    {
        $penalty = $this->record();

        $this->actingAs($this->manager)->post(route('penalties.explain', $penalty->id), ['explanation' => 'Không phải tôi giải trình'])
            ->assertForbidden();
        $this->actingAs($this->teacher)->post(route('penalties.explain', $penalty->id), ['explanation' => 'ngắn'])
            ->assertSessionHasErrors('explanation');

        $this->actingAs($this->teacher)->post(route('penalties.explain', $penalty->id), [
            'explanation' => 'Hôm đó em bị ốm, đã báo trợ giảng nộp giúp nhưng bị sót.',
        ])->assertSessionHasNoErrors();

        $penalty->refresh();
        $this->assertSame('explained', $penalty->status);
        $this->assertNotNull($penalty->explained_at);

        // Không giải trình lại sau khi đã chuyển bước
        $this->actingAs($this->teacher)->post(route('penalties.explain', $penalty->id), ['explanation' => 'Giải trình lần hai nhé'])
            ->assertStatus(422);
    }

    public function test_violator_sees_only_own_penalties_and_can_explain_from_list(): void
    {
        $own = $this->record();
        $other = Penalty::create([
            'code' => 'BB-OTHER', 'user_id' => $this->academicStaff->id, 'violation_type' => 'Vi phạm của người khác',
            'error_category' => 'operations', 'violation_date' => '2026-09-10', 'status' => 'pending',
        ]);

        $this->actingAs($this->teacher)->get(route('penalties.index'))
            ->assertOk()
            ->assertSee($own->code)
            ->assertDontSee($other->code)
            ->assertSee('Gửi giải trình');
    }

    public function test_confirmer_depends_on_error_category(): void
    {
        $academic = $this->record('academic');
        $operations = $this->record('operations');

        // Lỗi chuyên môn: CM (Học vụ/Quản lý) không chốt được, HT chốt được
        $this->actingAs($this->academicStaff)->post(route('penalties.confirm', $academic->id), ['decision' => 'fine', 'amount' => 100000])
            ->assertForbidden();
        $this->actingAs($this->manager)->post(route('penalties.confirm', $academic->id), ['decision' => 'fine', 'amount' => 100000])
            ->assertForbidden();
        $this->actingAs($this->academicLead)->post(route('penalties.confirm', $academic->id), ['decision' => 'fine', 'amount' => 100000])
            ->assertSessionHasNoErrors();
        $this->assertSame('fined', $academic->fresh()->status);

        // Lỗi vận hành: HT không chốt được, CM chốt được
        $this->actingAs($this->academicLead)->post(route('penalties.confirm', $operations->id), ['decision' => 'error'])
            ->assertForbidden();
        $this->actingAs($this->academicStaff)->post(route('penalties.confirm', $operations->id), ['decision' => 'error'])
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $operations->fresh()->status);

        // Admin luôn chốt được
        $this->actingAs($this->admin)->post(route('penalties.confirm', $operations->id), ['decision' => 'fine', 'amount' => 50000])
            ->assertSessionHasNoErrors();
        $this->assertSame('fined', $operations->fresh()->status);
    }

    public function test_fine_requires_amount_and_sets_two_day_due_date(): void
    {
        $this->travelTo(Carbon::parse('2026-09-11 09:00:00'));
        $penalty = $this->record();

        $this->actingAs($this->academicLead)->post(route('penalties.confirm', $penalty->id), ['decision' => 'fine'])
            ->assertSessionHasErrors('amount');
        $this->assertSame('pending', $penalty->fresh()->status);

        $this->actingAs($this->academicLead)->post(route('penalties.confirm', $penalty->id), [
            'decision' => 'fine', 'amount' => 150000, 'decision_note' => 'Tái phạm lần 2',
        ])->assertSessionHasNoErrors();

        $penalty->refresh();
        $this->assertSame('fined', $penalty->status);
        $this->assertEquals(150000, $penalty->amount);
        $this->assertSame('2026-09-13', $penalty->due_date->toDateString());
        $this->assertSame($this->academicLead->id, $penalty->decided_by);
        $this->assertFalse($penalty->isOverdue());

        $this->travelTo(Carbon::parse('2026-09-14 09:00:00'));
        $this->assertTrue($penalty->fresh()->isOverdue());
        $this->assertSame('Quá hạn nộp — sẽ trừ lương', $penalty->fresh()->status_label);
    }

    public function test_payroll_deducts_only_overdue_unpaid_fines(): void
    {
        $this->teacher->update(['base_salary' => 5000000]);
        $base = ['user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn', 'error_category' => 'operations', 'violation_date' => '2026-09-05'];
        $overdue = Penalty::create($base + ['code' => 'BB-1', 'amount' => 100000, 'status' => 'fined', 'due_date' => '2026-09-20']);
        $notDueYet = Penalty::create($base + ['code' => 'BB-2', 'amount' => 70000, 'status' => 'fined', 'due_date' => '2026-09-26']);
        $paid = Penalty::create($base + ['code' => 'BB-3', 'amount' => 50000, 'status' => 'paid', 'due_date' => '2026-09-10', 'paid_at' => '2026-09-09']);
        $pending = Penalty::create($base + ['code' => 'BB-4', 'amount' => 30000, 'status' => 'pending']);

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));
        $period = $this->period(9);
        $period->calculatePayrollForPeriod();

        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->teacher->id)->firstOrFail();
        $this->assertEquals(100000, $record->penalty_deduction);
        $this->assertSame($record->id, $overdue->fresh()->payroll_record_id);
        $this->assertNull($notDueYet->fresh()->payroll_record_id);
        $this->assertNull($paid->fresh()->payroll_record_id);
        $this->assertNull($pending->fresh()->payroll_record_id);

        // Hạn nộp qua đi trước khi duyệt → bắt buộc tính lại, lần tính sau trừ thêm
        $this->travelTo(Carbon::parse('2026-09-27 10:00:00'));
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $period->calculatePayrollForPeriod();
        $this->assertEquals(170000, $record->fresh()->penalty_deduction);

        $this->finalizeKpi($period);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('deducted', $overdue->fresh()->status);
        $this->assertSame('deducted', $notDueYet->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_overdue_fine_from_previous_month_is_deducted_in_next_period(): void
    {
        $this->teacher->update(['base_salary' => 5000000]);
        // Vi phạm cuối tháng 8, quyết phạt 1/9, hạn nộp 3/9 → trừ ở kỳ tháng 9
        $penalty = Penalty::create([
            'code' => 'BB-CARRY', 'user_id' => $this->teacher->id, 'violation_type' => 'Nghỉ dạy không phép',
            'error_category' => 'operations', 'violation_date' => '2026-08-31', 'amount' => 200000,
            'status' => 'fined', 'due_date' => '2026-09-03',
        ]);

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
        $august = $this->period(8);
        $august->calculatePayrollForPeriod();
        $this->assertEquals(0, PayrollRecord::where('payroll_period_id', $august->id)->value('penalty_deduction'));

        $september = $this->period(9);
        $september->calculatePayrollForPeriod();
        $this->assertEquals(200000, PayrollRecord::where('payroll_period_id', $september->id)->value('penalty_deduction'));
        $this->assertNotNull($penalty->fresh()->payroll_record_id);
    }

    public function test_status_changes_are_blocked_once_deducted_in_locked_period(): void
    {
        $period = $this->period(9, 2026, 'approved');
        $record = PayrollRecord::create(['payroll_period_id' => $period->id, 'user_id' => $this->teacher->id, 'net_salary' => 1000000]);
        $penalty = Penalty::create([
            'code' => 'BB-LOCK', 'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn', 'error_category' => 'operations',
            'violation_date' => '2026-10-02', 'amount' => 100000, 'status' => 'fined', 'due_date' => '2026-09-20',
            'payroll_record_id' => $record->id,
        ]);

        $this->actingAs($this->admin)->post(route('penalties.mark-paid', $penalty->id))->assertSessionHasErrors('penalty');
        $this->actingAs($this->admin)->post(route('penalties.resolve', $penalty->id))->assertSessionHasErrors('penalty');
        $this->assertSame('fined', $penalty->fresh()->status);

        $penalty->update(['status' => 'confirmed']);
        $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id))->assertSessionHasErrors('penalty');
        $this->assertSame('confirmed', $penalty->fresh()->status);
    }

    public function test_marking_paid_before_due_date_prevents_deduction(): void
    {
        $this->travelTo(Carbon::parse('2026-09-11 09:00:00'));
        $penalty = $this->record('operations');
        $this->actingAs($this->manager)->post(route('penalties.confirm', $penalty->id), ['decision' => 'fine', 'amount' => 80000]);
        $this->actingAs($this->manager)->post(route('penalties.mark-paid', $penalty->id))->assertSessionHasNoErrors();

        $penalty->refresh();
        $this->assertSame('paid', $penalty->status);
        $this->assertNotNull($penalty->paid_at);

        $this->teacher->update(['base_salary' => 5000000]);
        $this->travelTo(Carbon::parse('2026-09-30 09:00:00'));
        $period = $this->period(9);
        $period->calculatePayrollForPeriod();
        $this->assertEquals(0, PayrollRecord::where('payroll_period_id', $period->id)->value('penalty_deduction'));
    }

    public function test_decider_cannot_decide_own_penalty(): void
    {
        $penalty = Penalty::create([
            'code' => 'BB-SELF', 'user_id' => $this->academicLead->id, 'violation_type' => 'Dạy sai tiến độ giáo trình',
            'error_category' => 'academic', 'violation_date' => '2026-09-10', 'status' => 'pending',
        ]);

        $this->actingAs($this->academicLead)->post(route('penalties.confirm', $penalty->id), ['decision' => 'error'])
            ->assertForbidden();
    }

    public function test_list_supports_search_status_filter_and_pagination(): void
    {
        foreach (range(1, 18) as $i) {
            Penalty::create([
                'code' => sprintf('BB-L%02d', $i), 'user_id' => $this->teacher->id,
                'violation_type' => $i === 1 ? 'Vi phạm đặc biệt XYZ' : 'Đi muộn', 'error_category' => 'operations',
                'violation_date' => '2026-09-10', 'status' => $i <= 3 ? 'explained' : 'pending',
            ]);
        }

        $this->actingAs($this->manager)->get(route('penalties.index'))
            ->assertOk()
            ->assertViewHas('penalties', fn ($paginator) => $paginator->total() === 18 && $paginator->count() === 15);

        $this->actingAs($this->manager)->get(route('penalties.index', ['search' => 'XYZ']))
            ->assertOk()
            ->assertSee('BB-L01')
            ->assertDontSee('BB-L02');

        $this->actingAs($this->manager)->get(route('penalties.index', ['status' => 'explained']))
            ->assertOk()
            ->assertViewHas('penalties', fn ($paginator) => $paginator->total() === 3);
    }
}

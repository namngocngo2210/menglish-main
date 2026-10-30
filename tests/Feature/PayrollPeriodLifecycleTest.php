<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\BackgroundTask;
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
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

/** TK-0016: chốt chỉ đóng băng phiếu; gửi GV xem mới khóa dữ liệu nguồn và tất toán. */
class PayrollPeriodLifecycleTest extends TestCase
{
    use FinalizesPayrollKpi;
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $manager;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
        $branch = Branch::create(['code' => 'TK16', 'name' => 'Cơ sở vòng đời lương', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->manager = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->manager->assignRole(Roles::MANAGER);
        $this->teacher = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'base_salary' => 0]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->classModel = ClassModel::create([
            'code' => 'TK16-01', 'name' => 'Lớp vòng đời lương', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function period(string $status = PayrollPeriod::STATUS_DRAFT, int $month = 9): PayrollPeriod
    {
        $start = Carbon::create(2026, $month, 1);

        return PayrollPeriod::create([
            'code' => 'PR-TK16-'.$month, 'title' => 'Bảng lương TK-0016 tháng '.$month,
            'month' => $month, 'year' => 2026, 'start_date' => $start,
            'end_date' => $start->copy()->endOfMonth(), 'status' => $status,
        ]);
    }

    private function calculatedPeriod(): PayrollPeriod
    {
        TeacherTimesheet::create([
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-09-15', 'hours' => 2, 'hourly_rate' => 300000,
            'type' => 'regular', 'status' => 'valid',
        ]);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->finalizeKpi($period);

        return $period->fresh();
    }

    public function test_allowed_transitions_record_actor_and_time_and_update_records(): void
    {
        $period = $this->calculatedPeriod();
        $steps = [
            ['approve', PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED, 'confirmed'],
            ['reopen', PayrollPeriod::STATUS_CLOSED, PayrollPeriod::STATUS_DRAFT, 'pending'],
            ['approve', PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED, 'confirmed'],
            ['publish', PayrollPeriod::STATUS_CLOSED, PayrollPeriod::STATUS_PUBLISHED, 'confirmed'],
            ['mark-paid', PayrollPeriod::STATUS_PUBLISHED, PayrollPeriod::STATUS_PAID, 'paid'],
        ];
        foreach ($steps as $index => [$action, $from, $to, $recordStatus]) {
            $this->travelTo(Carbon::parse('2026-10-12 09:00:00')->addMinutes($index));
            $this->actingAs($this->admin)->post(route('payroll.periods.'.$action, $period->id))
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($to, $period->fresh()->status);
            $this->assertSame($recordStatus, $period->records()->firstOrFail()->status);
            $transition = $period->transitions()->latest('id')->firstOrFail();
            $this->assertSame($from, $transition->from_status);
            $this->assertSame($to, $transition->to_status);
            $this->assertSame($this->admin->id, $transition->user_id);
            $this->assertTrue($transition->created_at->equalTo(now()));
            $this->assertTrue($period->lastTransitionAt($to)->equalTo(now()));
        }
        $this->assertSame(5, $period->transitions()->count());
    }

    public function test_forbidden_transitions_and_double_close_return_422_without_history(): void
    {
        $period = $this->calculatedPeriod();
        $cases = [
            PayrollPeriod::STATUS_DRAFT => ['reopen', 'publish', 'mark-paid'],
            PayrollPeriod::STATUS_CLOSED => ['approve', 'mark-paid'],
            PayrollPeriod::STATUS_PUBLISHED => ['approve', 'reopen', 'publish'],
            PayrollPeriod::STATUS_PAID => ['approve', 'reopen', 'publish', 'mark-paid'],
        ];
        foreach ($cases as $status => $actions) {
            $period->update(['status' => $status]);
            foreach ($actions as $action) {
                $this->actingAs($this->admin)->post(route('payroll.periods.'.$action, $period->id))->assertStatus(422);
                $this->assertSame($status, $period->fresh()->status);
                $this->assertSame(0, $period->transitions()->count());
            }
        }
        $period->update(['status' => PayrollPeriod::STATUS_DRAFT]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertStatus(422);
        $this->assertSame(1, $period->transitions()->count());
    }

    public function test_transition_permissions_are_enforced(): void
    {
        $period = $this->period();
        foreach (['approve', 'reopen', 'publish', 'mark-paid'] as $action) {
            $this->actingAs($this->manager)->post(route('payroll.periods.'.$action, $period->id))->assertForbidden();
        }
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $this->assertSame(0, $period->transitions()->count());
    }

    public function test_recalculation_is_only_allowed_in_draft_and_closed_payslips_cannot_be_edited(): void
    {
        $period = $this->calculatedPeriod();
        $record = $period->records()->firstOrFail();
        Queue::fake();
        $this->actingAs($this->admin)->post(route('payroll.periods.calculate', $period->id))->assertRedirect();
        $this->assertDatabaseHas('background_tasks', ['type' => BackgroundTask::PAYROLL_CALCULATE, 'status' => BackgroundTask::QUEUED]);
        foreach (PayrollPeriod::FROZEN_STATUSES as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->admin)->post(route('payroll.periods.calculate', $period->id))->assertStatus(422);
        }
        $period->update(['status' => PayrollPeriod::STATUS_CLOSED]);
        $this->actingAs($this->admin)->post(route('payroll.records.update', $record->id), ['foreign_session_pay' => 50000])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), ['retention_tier' => 25000, 'lines' => []])->assertStatus(422);
        $this->assertSame(0.0, (float) $record->fresh()->foreign_session_pay);
    }

    public function test_calculation_model_refuses_frozen_period_even_with_a_stale_draft_instance(): void
    {
        $period = $this->calculatedPeriod();
        PayrollPeriod::whereKey($period->id)->update(['status' => PayrollPeriod::STATUS_CLOSED]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $period->calculatePayrollForPeriod();
    }

    public function test_closed_source_data_is_open_but_published_period_rejects_manual_timesheet(): void
    {
        $period = $this->calculatedPeriod();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertTrue($period->fresh()->isFrozen());
        $this->assertFalse($period->fresh()->isLocked());
        $this->assertFalse(PayrollPeriod::isLockedFor('2026-09-15'));
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertSessionHasNoErrors();
        $this->assertTrue($period->fresh()->isLocked());
        $this->assertTrue(PayrollPeriod::isLockedFor('2026-09-15'));
        $this->actingAs($this->admin)->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->teacher->id, 'class_id' => $this->classModel->id, 'teaching_date' => '2026-09-20',
            'time_in' => '18:00', 'time_out' => '20:00', 'type' => 'regular', 'notes' => 'Bổ sung công sau khi gửi.',
        ])->assertSessionHasErrors('teaching_date');
        $this->assertDatabaseMissing('teacher_timesheets', ['teaching_date' => '2026-09-20']);
    }

    public function test_publish_refuses_source_changes_after_close_until_reopened_and_recalculated(): void
    {
        $period = $this->calculatedPeriod();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->travelTo(now()->addMinute());
        TeacherTimesheet::where('user_id', $this->teacher->id)->update(['hours' => 3, 'updated_at' => now()]);
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertSessionHasErrors('period');
        $this->assertSame(PayrollPeriod::STATUS_CLOSED, $period->fresh()->status);
        $this->assertSame(1, $period->transitions()->count());
        $this->assertDatabaseMissing('admin_notifications', ['type' => 'payroll_published']);
        $this->actingAs($this->admin)->post(route('payroll.periods.reopen', $period->id))->assertSessionHasNoErrors();
        $period->refresh()->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertSessionHasNoErrors();
        $this->assertSame(PayrollPeriod::STATUS_PUBLISHED, $period->fresh()->status);
    }

    public function test_close_refuses_an_uncalculated_period_or_a_pending_calculation(): void
    {
        $period = $this->period();
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $period->calculatePayrollForPeriod();
        BackgroundTask::create([
            'type' => BackgroundTask::PAYROLL_CALCULATE, 'status' => BackgroundTask::RUNNING,
            'title' => 'Tính lương TK-0016', 'user_id' => $this->admin->id, 'payload' => ['period_id' => $period->id],
        ]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasErrors('period');
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);
        $this->assertSame(0, $period->transitions()->count());
    }

    public function test_close_ignores_a_pending_calculation_for_another_period(): void
    {
        $period = $this->calculatedPeriod();
        $other = $this->period(PayrollPeriod::STATUS_DRAFT, 8);
        BackgroundTask::create([
            'type' => BackgroundTask::PAYROLL_CALCULATE, 'status' => BackgroundTask::QUEUED,
            'title' => 'Tính kỳ lương khác', 'user_id' => $this->admin->id, 'payload' => ['period_id' => $other->id],
        ]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame(PayrollPeriod::STATUS_CLOSED, $period->fresh()->status);
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $other->fresh()->status);
    }

    public function test_only_publish_deducts_penalties_and_notifies_staff_once(): void
    {
        $period = $this->calculatedPeriod();
        $record = $period->records()->firstOrFail();
        $penalty = Penalty::create([
            'code' => 'BB-TK16', 'user_id' => $this->teacher->id, 'violation_type' => 'Đi muộn',
            'violation_date' => '2026-09-15', 'amount' => 100000, 'status' => 'fined',
            'payroll_record_id' => $record->id,
        ]);
        $record->update(['penalty_deduction' => 100000]);
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('fined', $penalty->fresh()->status);
        $this->assertDatabaseMissing('admin_notifications', ['type' => 'payroll_published']);
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('deducted', $penalty->fresh()->status);
        $notice = AdminNotification::where('type', 'payroll_published')->sole();
        $this->assertSame($this->teacher->id, $notice->user_id);
        $this->assertSame('Phiếu lương '.$period->title.' đã được gửi', $notice->title);
        $this->assertSame(route('portal.my-salary', ['period_id' => $period->id]), $notice->data['link']);
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertStatus(422);
        $this->assertSame(1, AdminNotification::where('type', 'payroll_published')->count());
    }

    public function test_own_payslip_is_hidden_everywhere_until_published(): void
    {
        $this->teacher->givePermissionTo('payroll.view');
        Rbac::flushCache();
        $period = $this->calculatedPeriod();
        $record = $period->records()->firstOrFail();
        foreach ([PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED] as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->teacher->fresh())->get(route('portal.my-salary'))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('record', null));
            $this->get(route('payroll.records.show', $record->id))->assertNotFound();
            $this->get(route('payroll.periods.show', $period->id))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 0)->where('stats.records', 0));
            $this->get(route('payroll.periods.index'))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('periods.data.0.staff', 0)->where('periods.data.0.amount', 0));
            $csv = $this->get(route('payroll.periods.export', [$period->id, 'format' => 'csv']))->assertOk();
            $this->assertStringNotContainsString($this->teacher->name, $csv->streamedContent());
            $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk();
        }
        $this->actingAs($this->admin)->post(route('payroll.periods.publish', $period->id))->assertSessionHasNoErrors();
        $this->actingAs($this->teacher->fresh())->get(route('portal.my-salary'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('record.id', $record->id));
        $this->get(route('payroll.records.show', $record->id))->assertOk();
    }

    public function test_branch_scope_hides_own_unpublished_payslip_but_keeps_other_staff_visible(): void
    {
        $period = $this->calculatedPeriod();
        $teacherRecord = $period->records()->firstOrFail();
        $ownRecord = PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->manager->id,
            'salary_role' => Roles::MANAGER, 'employee_type' => PayrollRecord::TYPE_FULLTIME,
            'net_salary' => 1234567,
        ]);
        $this->manager->givePermissionTo(['payroll.view', 'payroll.scope_branch']);
        Rbac::flushCache();
        foreach ([PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED] as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->manager->fresh())->get(route('payroll.periods.show', $period->id))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('records.total', 1)->where('records.data.0.user_id', $this->teacher->id));
            $this->get(route('payroll.records.show', $ownRecord->id))->assertNotFound();
            $this->get(route('payroll.records.show', $teacherRecord->id))->assertOk();
            $this->actingAs($this->admin)->get(route('payroll.records.show', $ownRecord->id))->assertOk();
        }
        $period->update(['status' => PayrollPeriod::STATUS_PUBLISHED]);
        $this->actingAs($this->manager->fresh())->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 2));
        $this->get(route('payroll.records.show', $ownRecord->id))->assertOk();
    }

    public function test_all_scope_viewer_can_read_own_payslip_before_publication(): void
    {
        $period = $this->period();
        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $this->admin->id, 'net_salary' => 7654321,
        ]);
        foreach ([PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_CLOSED] as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk();
            $this->get(route('payroll.periods.show', $period->id))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 1));
        }
    }

    public function test_period_props_expose_server_actions_frozen_state_and_history(): void
    {
        $period = $this->calculatedPeriod();
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period.frozen', false)->where('period.locked', false)
                ->where('period.status_label', 'Đang tính')
                ->where('period.actions.0.key', 'approve')->where('period.actions.0.enabled', true));
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period.frozen', true)->where('period.locked', false)
                ->where('period.status_label', 'Đã chốt')->has('period.actions', 2)
                ->where('period.transitions.0.to_status', PayrollPeriod::STATUS_CLOSED)
                ->where('period.transitions.0.user_name', $this->admin->name)
                ->where('period.transitions.0.created_at', now()->toIso8601String()));
    }

    public function test_migration_preserves_data_and_history_and_down_restores_legacy_values(): void
    {
        $migration = require database_path('migrations/2026_12_03_090000_payroll_period_lifecycle.php');
        $migration->down();
        $original = [];
        foreach (['draft', 'reviewing', 'approved', 'paid'] as $index => $status) {
            $period = $this->period($status, $index + 1);
            DB::table('payroll_periods')->where('id', $period->id)->update([
                'total_staff' => 3, 'total_hours' => 42.5, 'total_amount' => 7654321,
                'updated_at' => $status === 'approved' ? null : '2026-05-03 08:09:10',
            ]);
            $original[$status] = (array) DB::table('payroll_periods')->find($period->id);
        }
        $migration->up();
        $mapping = ['draft' => 'draft', 'reviewing' => 'draft', 'approved' => 'published', 'paid' => 'paid'];
        foreach ($original as $old => $row) {
            $migrated = (array) DB::table('payroll_periods')->find($row['id']);
            $this->assertSame($mapping[$old], $migrated['status']);
            unset($migrated['status'], $row['status']);
            $this->assertEquals($row, $migrated);
            $transition = DB::table('payroll_period_transitions')->where('payroll_period_id', $row['id'])->first();
            if ($mapping[$old] === $old) {
                $this->assertNull($transition);
            } else {
                $this->assertSame($old, $transition->from_status);
                $this->assertSame($mapping[$old], $transition->to_status);
                $this->assertNull($transition->user_id);
                $this->assertSame('Chuyển đổi trạng thái cũ (TK-0016)', $transition->note);
                $this->assertSame($row['updated_at'] ?? now()->toDateTimeString(), $transition->created_at);
            }
        }
        $this->assertDatabaseCount('payroll_period_transitions', 2);
        $closed = $this->period(PayrollPeriod::STATUS_CLOSED, 5);
        $migration->down();
        foreach ($original as $old => $row) {
            $this->assertDatabaseHas('payroll_periods', ['id' => $row['id'], 'status' => $old]);
        }
        $this->assertSame('approved', $closed->fresh()->status);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('payroll_period_transitions'));
        // Khôi phục schema để các kiểm tra sau không phụ thuộc thứ tự chạy.
        $migration->up();
    }
}

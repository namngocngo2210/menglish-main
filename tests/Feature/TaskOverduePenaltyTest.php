<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\SlaSetting;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Sla\Sla;
use App\Services\Sla\SlaBreachService;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TK-2026-0078: "Phạt khi quá hạn" khi giao việc (AC-S14 / AC-S15 / AC-S08), validation, không trừ lương hai lần.
 */
class TaskOverduePenaltyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->branch = Branch::create(['name' => 'Cơ sở PQH', 'code' => 'PQH', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
        $this->staff = $this->makeUser('assistant', ['hourly_rate' => 300000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'taskTitle' => 'Nộp báo cáo tuần',
            'assignee' => $this->staff->id,
            'dueDate' => now()->addDay()->toDateString(),
            'taskType' => 'one-time',
        ];
    }

    /** Việc giao tay đã qua hạn 1 ngày. */
    private function overdueTask(array $attributes = []): WorkTask
    {
        return WorkTask::create($attributes + [
            'title' => 'Việc quá hạn', 'creator_id' => $this->admin->id, 'assignee_id' => $this->staff->id,
            'task_type' => 'one_time', 'due_date' => now()->subDay()->toDateString(), 'due_time' => '10:00', 'status' => 'new',
        ]);
    }

    // ───────── Form / validation ─────────

    public function test_penalty_on_without_amount_is_rejected(): void
    {
        foreach ([null, '', '0', '-5'] as $amount) {
            $this->actingAs($this->admin)->post(route('tasks.store'), $this->payload(['phat_khi_qua_han' => '1', 'muc_phat_qua_han' => $amount]))
                ->assertSessionHasErrors('muc_phat_qua_han');
        }
        $this->assertSame(0, WorkTask::count());
    }

    public function test_penalty_on_with_amount_is_stored(): void
    {
        $this->actingAs($this->admin)->post(route('tasks.store'), $this->payload(['phat_khi_qua_han' => '1', 'muc_phat_qua_han' => '50000']))
            ->assertSessionHasNoErrors();

        $task = WorkTask::firstOrFail();
        $this->assertTrue($task->phat_khi_qua_han);
        $this->assertEquals(50000, $task->muc_phat_qua_han);
        $this->assertNull($task->sla_rule_key);
    }

    public function test_penalty_off_ignores_amount_and_defaults_to_off(): void
    {
        $this->actingAs($this->admin)->post(route('tasks.store'), $this->payload(['phat_khi_qua_han' => '0', 'muc_phat_qua_han' => '70000']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('tasks.store'), $this->payload(['taskTitle' => 'Không gửi cờ']))
            ->assertSessionHasNoErrors();

        foreach (WorkTask::all() as $task) {
            $this->assertFalse($task->phat_khi_qua_han);
            $this->assertNull($task->muc_phat_qua_han);
        }
    }

    public function test_create_form_shows_penalty_toggle(): void
    {
        $this->actingAs($this->admin)->get(route('tasks.create'))->assertOk()->assertSee('Phạt khi quá hạn');
    }

    // ───────── AC-S14 / AC-S15 ─────────

    public function test_ac_s14_manual_task_penalty_on_creates_fined_violation_and_payroll_deduction(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000]);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $task->fresh()->status);
        $penalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();
        $this->assertSame('fined', $penalty->status);
        $this->assertSame($this->staff->id, $penalty->user_id);
        $this->assertEquals(80000, $penalty->amount);
        $this->assertSame('task_overdue', $penalty->auto_source);
        $this->assertNull($penalty->due_date);
        $this->assertSame('Đã chốt phạt — trừ vào lương', $penalty->status_label);

        $period = $this->period($penalty->violation_date);
        $record = $this->recordFor($period);
        $this->assertEquals(80000, $record->penalty_deduction);
        $this->assertSame($record->id, $penalty->fresh()->payroll_record_id);
    }

    public function test_academic_roles_only_become_overdue_without_penalties(): void
    {
        $tasks = [];
        foreach ([Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF] as $role) {
            $assignee = $this->makeUser($role);
            $tasks[] = $this->overdueTask([
                'assignee_id' => $assignee->id, 'phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000,
            ]);
        }

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        foreach ($tasks as $task) {
            $this->assertSame('overdue', $task->fresh()->status);
        }
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    public function test_blocked_task_becomes_overdue_and_notifies_once_without_penalty(): void
    {
        $task = $this->overdueTask([
            'status' => 'blocked', 'phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000,
        ]);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $task->fresh()->status);
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
        $this->assertDatabaseCount('admin_notifications', 1);
        $this->assertDatabaseHas('admin_notifications', [
            'user_id' => $this->staff->id, 'title' => 'Công việc quá hạn: '.$task->title,
        ]);
    }

    public function test_cancelling_auto_penalty_removes_the_stored_payroll_deduction(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000]);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $penalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();
        $period = $this->period($penalty->violation_date);
        $record = $this->recordFor($period);
        $netBefore = (float) $record->net_salary;
        $this->assertEquals(80000, $record->penalty_deduction);

        $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $penalty->fresh()->status);
        $this->assertNull($penalty->fresh()->payroll_record_id);
        $this->assertEquals(0, $record->fresh()->penalty_deduction);
        $this->assertEquals($netBefore + 80000, $record->fresh()->net_salary);
        $this->assertEquals($period->records()->sum('net_salary'), $period->fresh()->total_amount);
    }

    public function test_cancelling_auto_penalty_preserves_other_penalty_deductions(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000]);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $penalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();
        $other = Penalty::create([
            'code' => Penalty::generateCode(), 'user_id' => $this->staff->id,
            'violation_type' => 'Manual violation', 'violation_date' => $penalty->violation_date,
            'amount' => 30000, 'status' => 'fined', 'due_date' => today()->addDay(),
        ]);
        $period = $this->period($penalty->violation_date);
        $record = $this->recordFor($period);
        $this->assertEquals(110000, $record->penalty_deduction);

        $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(30000, $record->fresh()->penalty_deduction);
        $this->assertSame('fined', $other->fresh()->status);
        $this->assertEquals(30000, $other->fresh()->amount);
        $this->assertSame($record->id, $other->fresh()->payroll_record_id);
    }

    public function test_auto_penalty_cannot_be_cancelled_in_a_frozen_payroll_period(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000]);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $penalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();
        $period = $this->period($penalty->violation_date);
        $record = $this->recordFor($period);

        foreach (PayrollPeriod::FROZEN_STATUSES as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->admin)->get(route('penalties.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('penalties.data.0.can_cancel', false));
            $response = $this->actingAs($this->admin)->post(route('penalties.cancel', $penalty->id));
            if ($status === PayrollPeriod::STATUS_CLOSED) {
                $response->assertUnprocessable();
            } else {
                $response->assertRedirect()->assertSessionHasErrors('penalty');
            }
            $this->assertSame('fined', $penalty->fresh()->status);
            $this->assertSame($record->id, $penalty->fresh()->payroll_record_id);
            $this->assertEquals(80000, $record->fresh()->penalty_deduction);
        }
    }

    public function test_ac_s15_manual_task_penalty_off_only_becomes_overdue(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => false]);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $task->fresh()->status);
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    public function test_legacy_task_with_default_columns_is_not_penalized(): void
    {
        $task = $this->overdueTask();
        $this->assertFalse($task->fresh()->phat_khi_qua_han);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    // ───────── Guards: không phạt/trừ hai lần ─────────

    public function test_rerun_and_cancelled_penalty_never_duplicate(): void
    {
        $task = $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000]);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $penalty = Penalty::withoutViewerScope()->where('work_task_id', $task->id)->sole();

        // Chạy lại, và cả khi việc bị đưa về mở lại sau khi biên bản bị hủy.
        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $penalty->update(['status' => 'cancelled']);
        $task->update(['status' => 'in_progress']);
        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame(1, Penalty::withoutViewerScope()->where('work_task_id', $task->id)->count());
    }

    public function test_task_not_yet_due_or_completed_is_not_penalized(): void
    {
        $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000, 'due_date' => now()->addDay()->toDateString()]);
        $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000, 'status' => 'completed']);
        $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000, 'status' => 'canceled']);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    public function test_sla_sourced_task_is_skipped_by_manual_penalty_job(): void
    {
        // Dù bị gắn cờ nhầm, việc SLA chỉ do mốc SLA lập biên bản → job giao tay không lập thêm.
        $this->overdueTask(['phat_khi_qua_han' => true, 'muc_phat_qua_han' => 80000, 'sla_rule_key' => 'crm.test_result']);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();

        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    // ───────── AC-S08: việc SLA đọc cấu hình mốc ─────────

    public function test_ac_s08_sla_task_stores_milestone_ref_and_penalty_off_gives_no_violation(): void
    {
        SlaSetting::updateOrCreate(['rule_key' => 'crm.test_result'], ['penalty' => false]);
        Sla::forget();
        $now = now();

        $service = app(SlaBreachService::class);
        $event = $service->open('crm.test_result', 'test', 1, $this->staff, $now->copy()->subDays(2), $now->copy()->subDay(), ['title' => 'Trả kết quả test', 'description' => 'x', 'branch_id' => $this->branch->id]);

        $task = WorkTask::findOrFail($event->work_task_id);
        $this->assertSame('crm.test_result', $task->sla_rule_key);
        $this->assertFalse($task->phat_khi_qua_han);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $service->breach($event->fresh(), 'Khách test', [], '/x', $now);

        $this->assertSame('overdue', $task->fresh()->status);
        $this->assertSame(0, Penalty::withoutViewerScope()->count());
    }

    public function test_sla_task_with_penalty_on_still_gets_exactly_one_pending_penalty(): void
    {
        SlaSetting::updateOrCreate(['rule_key' => 'crm.test_result'], ['penalty' => true, 'amount' => 30000]);
        Sla::forget();
        $now = now();

        $service = app(SlaBreachService::class);
        $event = $service->open('crm.test_result', 'test', 2, $this->staff, $now->copy()->subDays(2), $now->copy()->subDay(), ['title' => 'Trả kết quả test', 'description' => 'x', 'branch_id' => $this->branch->id]);

        $this->artisan('tasks:mark-overdue')->assertSuccessful();
        $service->breach($event->fresh(), 'Khách test', [], '/x', $now);

        $this->assertSame(1, Penalty::withoutViewerScope()->count());
        $this->assertSame('crm.test_result', Penalty::withoutViewerScope()->first()->auto_source);
    }

    // ───────── helpers lương ─────────

    private function period(\DateTimeInterface $date): PayrollPeriod
    {
        $start = Carbon::instance($date)->startOfMonth();
        $course = Course::create(['code' => 'PQH-C', 'name' => 'Khóa PQH', 'tuition_fee' => 1000000, 'is_active' => true]);
        $class = ClassModel::create(['code' => 'PQH-01', 'name' => 'Lớp PQH', 'course_id' => $course->id, 'branch_id' => $this->branch->id, 'teacher_id' => $this->staff->id, 'status' => 'active']);
        TeacherTimesheet::create([
            'user_id' => $this->staff->id, 'class_id' => $class->id, 'teaching_date' => $start->copy()->addDay()->toDateString(),
            'hours' => 2, 'hourly_rate' => 300000, 'type' => 'regular', 'status' => 'valid',
        ]);

        return PayrollPeriod::create([
            'code' => 'PR-PQH', 'title' => 'Bảng lương PQH', 'month' => $start->month, 'year' => $start->year,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'draft',
        ]);
    }

    private function recordFor(PayrollPeriod $period): PayrollRecord
    {
        $period->calculatePayrollForPeriod();

        return PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->staff->id)->firstOrFail();
    }
}

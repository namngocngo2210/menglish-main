<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\StaffAttendance;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FinalizesPayrollKpi;
use Tests\TestCase;

class PayrollTestPeriodTest extends TestCase
{
    use FinalizesPayrollKpi;
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $realStaff;

    private ClassModel $classModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->travelTo(Carbon::parse('2026-03-04 09:00:00'));
        $branch = Branch::create(['code' => 'TK36', 'name' => 'Payroll test branch', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'base_salary' => 0]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->teacher = User::factory()->create(['branch_id' => $branch->id, 'base_salary' => 0]);
        $this->teacher->assignRole(Roles::TEACHER_PARTTIME);
        $this->realStaff = User::factory()->create([
            'branch_id' => $branch->id, 'base_salary' => 8000000, 'created_at' => '2025-12-01',
        ]);
        $this->realStaff->assignRole(Roles::TEACHER_FULLTIME);
        $this->classModel = ClassModel::create([
            'code' => 'TK36-01', 'name' => 'Payroll test class', 'branch_id' => $branch->id,
            'teacher_id' => $this->teacher->id, 'status' => 'active',
        ]);
    }

    private function period(?array $allowlist = null, int $month = 1, ?string $code = null): PayrollPeriod
    {
        $start = Carbon::create(2026, $month, 1);

        return PayrollPeriod::create([
            'code' => $code ?? 'PR-TK36-'.$month, 'title' => 'Payroll test period',
            'month' => $month, 'year' => 2026, 'start_date' => $start,
            'end_date' => $start->copy()->endOfMonth(), 'status' => PayrollPeriod::STATUS_DRAFT,
            'staff_allowlist' => $allowlist,
        ]);
    }

    private function timesheet(User $user, string $date = '2026-01-15'): TeacherTimesheet
    {
        return TeacherTimesheet::create([
            'user_id' => $user->id, 'class_id' => $this->classModel->id, 'teaching_date' => $date,
            'hours' => 2, 'hourly_rate' => 300000, 'checkin_time' => '18:00', 'checkout_time' => '20:00',
            'type' => 'regular', 'source' => TeacherTimesheet::SOURCE_MANUAL, 'status' => 'valid',
        ]);
    }

    public function test_normal_period_includes_all_staff_and_missing_fulltime_record_blocks_close(): void
    {
        $this->timesheet($this->teacher);
        $period = $this->period();
        $this->assertFalse($period->isAllowListed());
        $period->calculatePayrollForPeriod();
        $this->assertEqualsCanonicalizing(
            [$this->teacher->id, $this->realStaff->id], $period->records()->pluck('user_id')->all()
        );
        $period->records()->where('user_id', $this->realStaff->id)->delete();
        $this->finalizeKpi($period);
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $period->id))
            ->assertSessionHasErrors('period');
        $this->assertSame(PayrollPeriod::STATUS_DRAFT, $period->fresh()->status);

        $period->refresh()->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->post(route('payroll.periods.publish', $period->id))->assertSessionHasNoErrors();
        $this->assertTrue(PayrollPeriod::isLockedFor('2026-01-20'));
        $this->get(route('payroll.timesheets.manual'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('lockedRanges', 1));
    }

    public function test_allowlisted_period_completes_lifecycle_without_locking_real_source_writes(): void
    {
        $this->timesheet($this->teacher);
        $period = $this->period([$this->teacher->id]);
        $period->calculatePayrollForPeriod();
        $this->finalizeKpi($period);
        $this->assertTrue($period->isAllowListed());
        $this->assertSame([$this->teacher->id], $period->records()->pluck('user_id')->all());
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('missingStaff.count', 0));
        foreach (['approve' => 'closed', 'publish' => 'published', 'mark-paid' => 'paid'] as $action => $status) {
            $this->post(route('payroll.periods.'.$action, $period->id))->assertSessionHasNoErrors();
            $this->assertSame($status, $period->fresh()->status);
            $this->assertFalse(PayrollPeriod::isLockedFor('2026-01-20'));
        }
        $this->get(route('payroll.timesheets.manual'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('lockedRanges', 0));
        $this->post(route('payroll.timesheets.manual.store'), [
            'user_id' => $this->realStaff->id, 'class_id' => $this->classModel->id,
            'teaching_date' => '2026-01-20', 'time_in' => '18:00', 'time_out' => '20:00',
            'type' => 'regular', 'notes' => 'Real staff timesheet remains writable',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(TeacherTimesheet::where('user_id', $this->realStaff->id)->whereDate('teaching_date', '2026-01-20')->exists());
        Storage::fake(Penalty::EVIDENCE_DISK);
        $this->travelTo(Carbon::parse('2026-01-20 21:00:00'));
        $this->post(route('penalties.store'), [
            'user_id' => $this->realStaff->id, 'violation_type' => 'Payroll QA write check',
            'violation_at' => '2026-01-20T20:00', 'evidence' => UploadedFile::fake()->image('evidence.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Penalty::withoutViewerScope()->where('user_id', $this->realStaff->id)->whereDate('violation_date', '2026-01-20')->exists());
    }

    public function test_allowlist_filters_departed_staff_and_removes_old_nonlisted_records(): void
    {
        $this->timesheet($this->teacher);
        $this->timesheet($this->realStaff);
        $this->teacher->update(['is_active' => false]);
        $this->realStaff->delete();
        $period = $this->period([$this->teacher->id]);
        PayrollRecord::create(['payroll_period_id' => $period->id, 'user_id' => $this->realStaff->id]);
        $period->calculatePayrollForPeriod();
        $this->assertSame([$this->teacher->id], $period->records()->pluck('user_id')->all());
    }

    public function test_empty_allowlist_produces_no_records(): void
    {
        $this->timesheet($this->teacher);
        $period = $this->period([]);
        $period->calculatePayrollForPeriod();
        $this->assertTrue($period->isAllowListed());
        $this->assertSame(0, $period->records()->count());
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
    }

    public static function refusedEnvironments(): array
    {
        return [['production'], ['testing'], ['local']];
    }

    #[DataProvider('refusedEnvironments')]
    public function test_command_refuses_every_nonstaging_environment(string $environment): void
    {
        $this->app['env'] = $environment;
        foreach ([[], ['--cleanup' => true]] as $options) {
            $this->artisan('payroll:test-period', $options)->expectsOutputToContain('staging')->assertFailed();
        }
        $this->assertDatabaseCount('payroll_periods', 0);
    }

    public function test_command_requires_known_active_staff_emails_before_writing(): void
    {
        $this->app['env'] = 'staging';
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->assignRole(Roles::TEACHER_PARTTIME);
        $student = User::factory()->create();
        $student->assignRole(Roles::STUDENT);
        foreach ([null, '', ' , ', 'unknown@example.test', $inactive->email, $student->email,
            $this->teacher->email.',unknown@example.test'] as $emails) {
            $options = $emails === null ? [] : ['--emails' => $emails];
            $this->artisan('payroll:test-period', $options)->assertFailed();
            $this->assertDatabaseCount('payroll_periods', 0);
            $this->assertDatabaseCount('teacher_timesheets', 0);
        }
    }

    public function test_command_refuses_golden_accounts_and_reopen_works_on_allowlisted_period(): void
    {
        $this->app['env'] = 'staging';
        $golden = User::factory()->create(['email' => 'FT-A.gd@test.menglish']);
        $golden->assignRole(Roles::TEACHER_FULLTIME);
        $this->artisan('payroll:test-period', ['--emails' => $this->teacher->email.',ft-a.gd@test.menglish'])
            ->expectsOutputToContain('Golden')->assertFailed();
        $this->assertDatabaseCount('payroll_periods', 0);

        $this->artisan('payroll:test-period', ['--emails' => $this->teacher->email])->assertSuccessful();
        $period = PayrollPeriod::where('code', 'PR-2026-TEST-A')->firstOrFail();
        $this->assertSame([$this->teacher->id], $period->records()->pluck('user_id')->all());
        $this->actingAs($this->admin);
        $this->post(route('payroll.periods.approve', $period->id))->assertSessionHasNoErrors();
        $this->post(route('payroll.periods.reopen', $period->id))->assertSessionHasNoErrors();
        $this->assertSame('draft', $period->fresh()->status);
    }

    public function test_command_is_idempotent_and_prepares_period_a_for_close(): void
    {
        $this->app['env'] = 'staging';
        $staff = User::factory()->create(['base_salary' => 8000000]);
        $staff->assignRole(Roles::MANAGER);
        $options = ['--emails' => ' '.$this->teacher->email.','.$staff->email.','.$this->teacher->email.' '];
        $this->artisan('payroll:test-period', $options)->assertSuccessful();
        $ids = PayrollPeriod::orderBy('id')->pluck('id')->all();
        $recordIds = PayrollRecord::orderBy('id')->pluck('id')->all();
        $timesheetIds = TeacherTimesheet::orderBy('id')->pluck('id')->all();
        $this->artisan('payroll:test-period', $options)->assertSuccessful();
        $this->assertSame($ids, PayrollPeriod::orderBy('id')->pluck('id')->all());
        $this->assertSame($recordIds, PayrollRecord::orderBy('id')->pluck('id')->all());
        $this->assertSame($timesheetIds, TeacherTimesheet::orderBy('id')->pluck('id')->all());
        $this->assertCount(2, $ids);
        $this->assertCount(1, $timesheetIds);
        $a = PayrollPeriod::where('code', 'PR-2026-TEST-A')->firstOrFail();
        $b = PayrollPeriod::where('code', 'PR-2026-TEST-B')->firstOrFail();
        $this->assertSame('2026-01-01', $a->start_date->toDateString());
        $this->assertSame('2026-01-31', $a->end_date->toDateString());
        $this->assertSame('2026-02-01', $b->start_date->toDateString());
        $this->assertSame('2026-02-28', $b->end_date->toDateString());
        foreach ([$a, $b] as $period) {
            $this->assertSame('draft', $period->status);
            $this->assertEqualsCanonicalizing([$this->teacher->id, $staff->id], $period->staff_allowlist);
        }
        $this->assertEqualsCanonicalizing([$this->teacher->id, $staff->id], $a->records()->pluck('user_id')->all());
        foreach ($a->records()->get() as $record) {
            $this->assertNotSame('pending', $record->kpi_state[0]);
            $this->assertSame(0.0, $record->netShortfall());
        }
        $this->app['env'] = 'testing';
        $this->actingAs($this->admin)->post(route('payroll.periods.approve', $a->id))->assertSessionHasNoErrors();
        $snapshot = $a->fresh()->getAttributes();
        $records = $a->records()->get()->map->getAttributes()->all();
        $this->app['env'] = 'staging';
        $this->artisan('payroll:test-period', $options)->expectsOutputToContain('skip')->assertSuccessful();
        $this->assertSame($snapshot, $a->fresh()->getAttributes());
        $this->assertSame($records, $a->records()->get()->map->getAttributes()->all());
    }

    public static function realDataKinds(): array
    {
        return [['timesheet'], ['penalty'], ['attendance'], ['period'], ['reserved-code']];
    }

    public function test_command_rolls_back_when_test_account_data_would_yield_negative_net(): void
    {
        $this->app['env'] = 'staging';
        $penalty = Penalty::create([
            'code' => 'BB-TK36-NEGATIVE', 'user_id' => $this->teacher->id,
            'violation_type' => 'Existing test penalty', 'violation_date' => '2026-01-15',
            'status' => 'fined', 'amount' => 2000000,
        ]);
        $this->artisan('payroll:test-period', ['--emails' => $this->teacher->email])
            ->expectsOutputToContain('negative net')->assertFailed();
        $this->assertDatabaseCount('payroll_periods', 0);
        $this->assertDatabaseCount('payroll_records', 0);
        $this->assertDatabaseCount('teacher_timesheets', 0);
        $this->assertNull($penalty->fresh()->payroll_record_id);
    }

    #[DataProvider('realDataKinds')]
    public function test_command_refuses_real_data_before_creating_either_period(string $kind): void
    {
        $this->app['env'] = 'staging';
        match ($kind) {
            'timesheet' => $this->timesheet($this->realStaff, '2026-02-15'),
            'penalty' => Penalty::create([
                'code' => 'BB-TK36', 'user_id' => $this->realStaff->id,
                'violation_type' => 'Real penalty', 'violation_date' => '2026-01-15',
            ]),
            'attendance' => StaffAttendance::create([
                'user_id' => $this->realStaff->id, 'branch_id' => $this->realStaff->branch_id,
                'work_date' => '2026-02-15', 'source' => StaffAttendance::SOURCE_MANUAL,
            ]),
            'period' => $this->period(null, 2),
            'reserved-code' => $this->period(null, 3, 'PR-2026-TEST-A'),
        };
        $count = PayrollPeriod::count();
        $this->artisan('payroll:test-period', ['--emails' => $this->teacher->email])->assertFailed();
        $this->assertDatabaseCount('payroll_periods', $count);
        $this->assertDatabaseCount('payroll_records', 0);
    }

    public function test_cleanup_removes_only_test_periods_their_children_and_tagged_timesheets(): void
    {
        $this->app['env'] = 'staging';
        $this->artisan('payroll:test-period', ['--emails' => $this->teacher->email])->assertSuccessful();
        $a = PayrollPeriod::where('code', 'PR-2026-TEST-A')->firstOrFail();
        $a->transitions()->create(['from_status' => 'draft', 'to_status' => 'closed', 'created_at' => now()]);
        $a->update(['status' => 'closed']);
        $normal = $this->period(null, 3, 'PR-2026-TEST-NORMAL');
        $normalRecord = PayrollRecord::create(['payroll_period_id' => $normal->id, 'user_id' => $this->realStaff->id]);
        $other = $this->period([$this->teacher->id], 4, 'PR-OTHER-QA');
        $untagged = $this->timesheet($this->teacher, '2026-01-20');
        $real = $this->timesheet($this->realStaff, '2026-03-15');
        $this->artisan('payroll:test-period', ['--cleanup' => true])->assertSuccessful();
        $this->assertDatabaseCount('payroll_periods', 2);
        $this->assertDatabaseHas('payroll_periods', ['id' => $normal->id, 'staff_allowlist' => null]);
        $this->assertDatabaseHas('payroll_periods', ['id' => $other->id]);
        $this->assertDatabaseHas('payroll_records', ['id' => $normalRecord->id]);
        $this->assertDatabaseCount('payroll_records', 1);
        $this->assertDatabaseCount('payroll_period_transitions', 0);
        $this->assertSame(2, TeacherTimesheet::whereIn('id', [$untagged->id, $real->id])->count());
        $this->assertDatabaseCount('teacher_timesheets', 2);
        $this->artisan('payroll:test-period', ['--cleanup' => true])->assertSuccessful();
        $this->assertDatabaseCount('payroll_periods', 2);
    }
}

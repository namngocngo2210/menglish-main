<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\KpiRetentionTier;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodLifecycle;
use App\Services\PayrollFormulaService;
use Carbon\Carbon;
use Database\Seeders\KpiRetentionTierSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PayrollKpiTierTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->branch = Branch::create(['code' => 'KPI-T', 'name' => 'KPI', 'is_active' => true]);
        $this->admin = $this->staff('admin');
    }

    private function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'KPI tháng 9', 'month' => 9, 'year' => 2026,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'draft',
        ]);
    }

    private function teacher(string $role, int $students): User
    {
        $teacher = $this->staff($role, ['base_salary' => $role === 'teacher_fulltime' ? 10000000 : 0]);
        $course = Course::firstOrCreate(['code' => 'KPI-T'], ['name' => 'KPI', 'tuition_fee' => 1000000, 'is_active' => true]);
        $class = ClassModel::create([
            'code' => 'KPI-'.$teacher->id, 'name' => 'KPI', 'course_id' => $course->id,
            'teacher_id' => $teacher->id, 'branch_id' => $this->branch->id, 'status' => 'active',
        ]);
        TeacherTimesheet::create([
            'user_id' => $teacher->id, 'class_id' => $class->id, 'teaching_date' => '2026-09-10',
            'hours' => 2, 'hourly_rate' => 200000, 'type' => 'regular', 'status' => 'valid',
        ]);
        for ($i = 0; $i < $students; $i++) {
            $student = Student::create([
                'code' => 'KPI-'.$teacher->id.'-'.$i, 'name' => 'HS KPI', 'phone' => '0900000000',
                'branch_id' => $this->branch->id, 'status' => 'studying',
            ]);
            ClassEnrollment::create([
                'class_id' => $class->id, 'student_id' => $student->id,
                'enrolled_at' => '2026-08-01', 'status' => 'active',
            ]);
        }

        return $teacher;
    }

    public function test_progressive_boundaries_and_parts(): void
    {
        $formula = app(PayrollFormulaService::class);
        foreach ([0 => 0, 5 => 125000, 6 => 155000, 8 => 215000, 10 => 275000, 11 => 305000] as $students => $amount) {
            $this->assertSame((float) $amount, $formula->retentionKpi($students, 0)['amount']);
        }
        $this->assertSame([], $formula->retentionKpi(0, 0)['parts']);
        $this->assertSame([['students' => 5, 'rate' => 25000.0], ['students' => 3, 'rate' => 30000.0]], $formula->retentionKpi(8, 0)['parts']);
        $this->assertSame([['students' => 5, 'rate' => 25000.0], ['students' => 6, 'rate' => 30000.0]], $formula->retentionKpi(11, 0)['parts']);
    }

    public function test_default_seeder_fills_an_empty_table_once(): void
    {
        KpiRetentionTier::query()->delete();
        $this->seed(KpiRetentionTierSeeder::class);
        $this->seed(KpiRetentionTierSeeder::class);
        $this->assertSame(2, KpiRetentionTier::count());
        $this->assertSame(215000.0, app(PayrollFormulaService::class)->retentionKpi(8, 0)['amount']);
    }

    public function test_open_ended_tiers_keep_decimal_money_exact(): void
    {
        KpiRetentionTier::query()->delete();
        KpiRetentionTier::create(['from_students' => 1, 'to_students' => 5, 'rate' => 25000.15]);
        KpiRetentionTier::create(['from_students' => 6, 'to_students' => null, 'rate' => 30000.25]);
        $result = app(PayrollFormulaService::class)->retentionKpi(11, 0);
        $this->assertSame(305002.25, $result['amount']);
        $this->assertSame([['students' => 5, 'rate' => 25000.15], ['students' => 6, 'rate' => 30000.25]], $result['parts']);
    }

    public function test_seniority_selects_the_greatest_eligible_band_and_whole_months(): void
    {
        KpiRetentionTier::create(['min_seniority_months' => 12, 'from_students' => 1, 'to_students' => 5, 'rate' => 30000]);
        KpiRetentionTier::create(['min_seniority_months' => 12, 'from_students' => 6, 'to_students' => 10, 'rate' => 35000]);
        $formula = app(PayrollFormulaService::class);
        $end = Carbon::parse('2026-09-30');
        foreach (['2024-09-30' => [24, 255000.0], '2026-06-30' => [3, 215000.0], '2025-10-01' => [11, 215000.0], '2025-09-30' => [12, 255000.0]] as $start => [$months, $amount]) {
            $teacher = $this->staff('teacher_fulltime', ['contract_start_date' => $start]);
            $this->assertSame($months, $formula->seniorityMonths($teacher, $end));
            $this->assertSame($amount, $formula->retentionKpi(8, $months)['amount']);
        }
        foreach ([null, '2026-10-01', '2026-09-01'] as $start) {
            $this->assertSame(0, $formula->seniorityMonths(new User(['contract_start_date' => $start]), $end));
        }
        KpiRetentionTier::where('min_seniority_months', 0)->delete();
        $this->assertSame(12, $formula->kpiTierBandFor(0)->first()->min_seniority_months);
        KpiRetentionTier::query()->delete();
        $this->assertSame(['amount' => 0.0, 'parts' => []], $formula->retentionKpi(8, 0));
    }

    public function test_both_teacher_types_are_proposed_finalized_and_invalidated_only_when_amount_changes(): void
    {
        $teachers = collect(['teacher_parttime', 'teacher_fulltime'])->map(fn ($role) => $this->teacher($role, 8));
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        foreach ($teachers as $teacher) {
            $record = $period->records()->where('user_id', $teacher->id)->firstOrFail();
            $this->assertSame(PayrollRecord::KPI_RETENTION, $record->kpi_source);
            $this->assertEquals(215000, $record->kpi_bonus);
            $this->assertSame('pending', $record->kpi_state[0]);
            $this->assertSame(0, data_get($record->calculation_details, 'kpi_tier.seniority_months'));
            $this->actingAs($this->admin)->post(route('payroll.records.adjust', $record->id), [
                'retention_tier' => 'ignored', 'kpi_manual_amount' => 'ignored', 'foreign_session_pay' => 70000,
            ])->assertSessionHasNoErrors();
            $this->assertNull($record->fresh()->retention_tier);
            $this->assertEquals($record->isPartTime() ? 70000 : 0, $record->fresh()->foreign_session_pay);
            $this->assertSame('pending', $record->fresh()->kpi_state[0]);
            $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('done', $record->fresh()->kpi_state[0]);
            $this->assertSame($this->admin->id, $record->fresh()->kpi_finalized_by);
            $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertInertia(fn (Assert $page) => $page
                ->where('record.kpi_finalized', true)->where('record.kpi_proposed', 215000)
                ->has('record.kpi_tier_parts', 2)->etc());
        }
        $period->calculatePayrollForPeriod();
        $this->assertTrue($period->records()->get()->every(fn ($r) => $r->kpi_state[0] === 'done'));
        ClassEnrollment::query()->whereIn('class_id', ClassModel::whereIn('teacher_id', $teachers->pluck('id'))->pluck('id'))
            ->get()->groupBy('class_id')->each(fn ($rows) => $rows->first()->delete());
        $period->calculatePayrollForPeriod();
        foreach ($period->records()->get() as $record) {
            $this->assertEquals(185000, $record->kpi_bonus);
            $this->assertSame('pending', $record->kpi_state[0]);
            $this->assertNull($record->kpi_finalized_at);
            $this->assertNull($record->kpi_finalized_by);
        }
    }

    public function test_zero_retained_students_do_not_block_closing_for_either_teacher_type(): void
    {
        $this->teacher('teacher_parttime', 0);
        $this->teacher('teacher_fulltime', 0);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->assertCount(2, $period->records);
        foreach ($period->records as $record) {
            $this->assertSame(['na', 'Không áp dụng'], $record->kpi_state);
            $this->assertEquals(0, $record->kpi_bonus);
            $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertStatus(422);
        }
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (Assert $page) => $page->where('kpiPending.count', 0)->etc());
        $this->travelTo($period->attendanceCloseAt()->addDay());
        $this->assertNull(app(PayrollPeriodLifecycle::class)->blockReason($period, PayrollPeriod::STATUS_CLOSED));
    }

    public function test_positive_retention_with_zero_rates_still_requires_finalization_without_other_pay(): void
    {
        $teacher = $this->teacher('teacher_parttime', 8);
        KpiRetentionTier::query()->update(['rate' => 0]);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $period->records()->where('user_id', $teacher->id)->firstOrFail();
        $this->assertEquals(0, $record->kpi_bonus);
        $this->assertSame('pending', $record->kpi_state[0]);
        $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertSessionHasNoErrors();
        $this->assertSame('done', $record->fresh()->kpi_state[0]);
    }

    public function test_zero_retention_is_not_applicable_even_when_there_were_students_at_period_start(): void
    {
        foreach (['teacher_parttime', 'teacher_fulltime'] as $role) {
            $teacher = $this->teacher($role, 1);
            $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
            Student::whereHas('enrollments', fn ($q) => $q->whereIn('class_id', ClassModel::where('teacher_id', $teacher->id)->pluck('id')))
                ->update(['status' => Student::STATUS_DROPPED]);
            $this->travelBack();
        }
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        foreach ($period->records()->get() as $record) {
            $this->assertSame(1, $record->retention_base_students);
            $this->assertSame(0, $record->retention_students);
            $this->assertSame('na', $record->kpi_state[0]);
            $this->assertEquals(0, $record->kpi_bonus);
        }
    }

    public function test_unfinalized_positive_retention_blocks_close_and_finalization_is_guarded(): void
    {
        $teacher = $this->teacher('teacher_parttime', 8);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $period->records()->firstOrFail();
        $this->travelTo($period->attendanceCloseAt()->addDay());
        $this->assertStringContainsString('chưa chốt KPI', app(PayrollPeriodLifecycle::class)->blockReason($period, PayrollPeriod::STATUS_CLOSED));
        $this->actingAs($this->admin)->get(route('payroll.periods.show', $period->id))
            ->assertInertia(fn (Assert $page) => $page->where('kpiPending.count', 1)->etc());
        $this->actingAs($teacher)->post(route('payroll.records.finalize-kpi', $record->id))->assertForbidden();
        foreach ([PayrollPeriod::STATUS_CLOSED, PayrollPeriod::STATUS_PUBLISHED, PayrollPeriod::STATUS_PAID] as $status) {
            $period->update(['status' => $status]);
            $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertStatus(422);
        }
    }

    public function test_manual_and_concurrent_kpi_cannot_use_teacher_finalization(): void
    {
        $period = $this->period();
        $lead = $this->staff('academic_lead');
        $record = PayrollRecord::create([
            'payroll_period_id' => $period->id, 'user_id' => $lead->id, 'employee_type' => 'fulltime',
            'salary_role' => 'academic_lead', 'kpi_source' => PayrollRecord::KPI_MANUAL,
            'retention_students' => 8, 'teaching_concurrent' => false,
        ]);
        $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertStatus(422);
        $record->update(['kpi_source' => PayrollRecord::KPI_RETENTION, 'teaching_concurrent' => true]);
        $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertStatus(422);
        $this->assertNull($record->fresh()->kpi_finalized_at);
    }

    public function test_tier_config_changes_require_recalculation_and_a_new_finalization(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));
        $teacher = $this->teacher('teacher_parttime', 8);
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $record = $period->records()->where('user_id', $teacher->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('payroll.records.finalize-kpi', $record->id))->assertSessionHasNoErrors();
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        $this->travelTo(now()->addMinute());
        $this->actingAs($this->admin)->post(route('payroll.config.kpi-tiers.store'), ['bands' => [
            ['min_seniority_months' => 0, 'tiers' => [['from_students' => 1, 'to_students' => null, 'rate' => 30000]]],
        ]])->assertSessionHasNoErrors();
        $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
        $this->assertStringContainsString('Đồng bộ & Tính lại', app(PayrollPeriodLifecycle::class)->blockReason($period->fresh(), PayrollPeriod::STATUS_CLOSED));
        $period->calculatePayrollForPeriod();
        $this->assertEquals(240000, $record->fresh()->kpi_bonus);
        $this->assertNull($record->fresh()->kpi_finalized_at);
    }

    public function test_contract_date_changes_require_recalculation_for_both_teacher_types(): void
    {
        KpiRetentionTier::create(['min_seniority_months' => 12, 'from_students' => 1, 'to_students' => null, 'rate' => 35000]);
        $teachers = collect(['teacher_parttime', 'teacher_fulltime'])->map(function ($role) {
            $teacher = $this->teacher($role, 8);
            $teacher->update(['contract_start_date' => '2026-06-30']);

            return $teacher;
        });
        $period = $this->period();
        $period->calculatePayrollForPeriod();
        $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        foreach ($teachers as $teacher) {
            $teacher->update(['contract_start_date' => '2024-09-30']);
            $this->assertTrue($period->fresh()->hasChangesSinceCalculation());
            $period->calculatePayrollForPeriod();
            $record = $period->records()->where('user_id', $teacher->id)->firstOrFail();
            $this->assertEquals(280000, $record->kpi_bonus);
            $this->assertSame(24, data_get($record->calculation_details, 'kpi_tier.seniority_months'));
            $this->assertFalse($period->fresh()->hasChangesSinceCalculation());
        }
    }

    public function test_config_contract_validation_permissions_and_add_only_seeding(): void
    {
        $teacher = $this->staff('teacher_parttime');
        $bands = [['min_seniority_months' => 0, 'tiers' => [
            ['from_students' => 1, 'to_students' => 5, 'rate' => 27000],
            ['from_students' => 6, 'to_students' => null, 'rate' => 32000],
        ]]];
        $this->actingAs($teacher)->get(route('payroll.config.kpi-tiers'))->assertForbidden();
        $this->actingAs($teacher)->post(route('payroll.config.kpi-tiers.store'), ['bands' => $bands])->assertForbidden();
        $this->actingAs($this->admin)->post(route('payroll.config.kpi-tiers.store'), ['bands' => $bands])->assertSessionHasNoErrors();
        $this->seed(KpiRetentionTierSeeder::class);
        $this->actingAs($this->admin)->get(route('payroll.config.kpi-tiers'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Payroll/Config/KpiTiers')->where('bands', $bands));
        $invalid = [
            [],
            [['min_seniority_months' => 12, 'tiers' => $bands[0]['tiers']]],
            [$bands[0], $bands[0]],
            [['min_seniority_months' => 0, 'tiers' => []]],
            [['min_seniority_months' => 0, 'tiers' => [['from_students' => 2, 'to_students' => null, 'rate' => 1]]]],
        ];
        foreach ([7, 5] as $from) {
            $bad = $bands;
            $bad[0]['tiers'][1]['from_students'] = $from;
            $invalid[] = $bad;
        }
        $bad = $bands;
        $bad[0]['tiers'][0]['to_students'] = null;
        $invalid[] = $bad;
        $bad = $bands;
        $bad[0]['tiers'][0]['rate'] = -1;
        $invalid[] = $bad;
        foreach ($invalid as $payload) {
            $this->actingAs($this->admin)->put(route('payroll.config.kpi-tiers.store'), ['bands' => $payload])->assertSessionHasErrors();
            $this->assertEquals(27000, KpiRetentionTier::orderBy('from_students')->first()->rate);
        }
    }
}

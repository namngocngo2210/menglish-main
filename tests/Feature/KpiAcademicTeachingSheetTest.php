<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\TeacherTimesheet;
use App\Models\User;
use App\Services\Kpi\KpiSheetService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/**
 * Học thuật kiêm nhiệm giảng dạy có thêm phiếu KPI giảng dạy (bộ tiêu chí GV part-time), riêng với phiếu KPI Học thuật:
 * tự tạo đầu kỳ, hiện thêm một dòng ở Phiếu KPI tháng, duyệt riêng, xem ở KPI của tôi và trên phiếu lương kiêm nhiệm.
 */
class KpiAcademicTeachingSheetTest extends TestCase
{
    use InteractsWithInertia, RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $lead;

    private User $plainLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));
        $this->branch = Branch::create(['name' => 'Cơ sở 1', 'code' => 'CS1', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);

        $this->lead = User::factory()->create(['name' => 'Học thuật Lan', 'branch_id' => $this->branch->id, 'is_active' => true, 'academic_teaching' => true, 'base_salary' => 15000000]);
        $this->lead->assignRole(Roles::ACADEMIC_LEAD);
        $this->plainLead = User::factory()->create(['name' => 'Học thuật Mai', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->plainLead->assignRole(Roles::ACADEMIC_LEAD);

        // Học thuật kiêm nhiệm đi dạy muộn 2 buổi trong tháng 10 → tiêu chí "Đi muộn giờ dạy" của phiếu giảng dạy tự đếm.
        $class = ClassModel::create(['code' => '9CLC', 'name' => 'Lớp 9CLC', 'branch_id' => $this->branch->id, 'teacher_id' => $this->lead->id, 'status' => 'active']);
        foreach (['2026-10-06' => 5, '2026-10-08' => 20] as $date => $late) {
            TeacherTimesheet::create([
                'user_id' => $this->lead->id, 'class_id' => $class->id, 'teaching_date' => $date, 'scheduled_time' => '18:00-19:30',
                'hours' => 1.5, 'type' => 'regular', 'status' => 'valid', 'late_minutes' => $late,
            ]);
        }
    }

    /** Số liệu điền tay đủ điểm cho mọi tiêu chí không tự đếm của bộ GV part-time. */
    private function manualParttime(): array
    {
        return KpiCriterion::forRole(Roles::TEACHER_PARTTIME)->active()->get()
            ->reject(fn (KpiCriterion $c) => $c->isAuto() && ! $c->isRateSource())
            ->mapWithKeys(fn (KpiCriterion $c) => [$c->id => $c->isRate() ? '100' : '0'])->all();
    }

    public function test_teaching_lead_gets_a_second_sheet_scored_with_the_parttime_set(): void
    {
        $this->artisan('kpi:create-sheets')->assertSuccessful();

        $this->assertSame(['main', 'teaching'], KpiEvaluation::where('user_id', $this->lead->id)->orderBy('track')->pluck('track')->all());
        $this->assertSame(['main'], KpiEvaluation::where('user_id', $this->plainLead->id)->pluck('track')->all());

        $sheets = app(KpiSheetService::class);
        $teaching = $sheets->sheet($this->lead, 10, 2026, null, KpiEvaluation::TRACK_TEACHING);
        $this->assertSame(Roles::TEACHER_PARTTIME, $teaching['role']);
        $late = $teaching['lines']->first(fn ($l) => $l['criterion']->name === 'Đi muộn giờ dạy');
        $this->assertSame([true, 2, 62.5], [$late['auto'], $late['value'], $late['level']]);
        $this->assertSame(Roles::ACADEMIC_LEAD, $sheets->sheet($this->lead, 10, 2026)['role']);

        // Tắt option kiêm nhiệm → không còn phiếu giảng dạy.
        $this->lead->update(['academic_teaching' => false]);
        $this->assertSame([KpiEvaluation::TRACK_MAIN], KpiSheetService::tracksFor($this->lead->fresh()));
    }

    public function test_monthly_list_shows_the_teaching_sheet_as_its_own_row(): void
    {
        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('staff.data', 2)
                ->where('staff.data.0.id', $this->lead->id)->has('staff.data.0.sheets', 2)
                ->where('staff.data.0.sheets.1.role', 'KPI giảng dạy (kiêm nhiệm)')
                ->where('staff.data.1.id', $this->plainLead->id)->has('staff.data.1.sheets', 1));

        // Lọc GV part-time: chỉ phiếu giảng dạy của Học thuật kiêm nhiệm.
        $this->actingAs($this->admin)->get(route('kpi.monthly', ['period' => '2026-10', 'role' => Roles::TEACHER_PARTTIME]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('staff.data', 1)
                ->where('staff.data.0.id', $this->lead->id)
                ->has('staff.data.0.sheets', 1)
                ->where('staff.data.0.sheets.0.track', 'teaching')
                ->where('staff.data.0.sheets.0.role', 'KPI giảng dạy (kiêm nhiệm)')
                ->where('staff.data.0.sheets.0.url', fn ($url) => str_contains($url, 'track=teaching')));
    }

    public function test_teaching_sheet_is_approved_separately_from_the_academic_sheet(): void
    {
        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->lead->id, 'period' => '2026-10', 'track' => 'teaching']), self::MODAL)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Evaluate')->where('track', 'teaching')
                ->where('staff.role_label', 'KPI giảng dạy (kiêm nhiệm)')->has('grades', 5));
        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->plainLead->id, 'period' => '2026-10', 'track' => 'teaching']), self::MODAL)
            ->assertNotFound();

        $this->actingAs($this->admin)->post(route('kpi.evaluate.store', $this->lead->id), [
            'month' => 10, 'year' => 2026, 'track' => 'teaching', 'action' => 'approve', 'actual' => $this->manualParttime(),
        ])->assertSessionHasNoErrors();

        $teaching = KpiEvaluation::where('user_id', $this->lead->id)->where('track', 'teaching')->firstOrFail();
        // 100 điểm − đi muộn 2 lần (8 × 37,5% = 3) = 97% → loại A, như GV part-time (báo cáo tháng 10 chưa tới hạn mùng 2).
        $this->assertSame(KpiEvaluation::STATUS_APPROVED, $teaching->status);
        $this->assertEquals(97, (float) $teaching->total_score);
        $this->assertNull(KpiEvaluation::where('user_id', $this->lead->id)->where('track', 'main')->first(), 'phiếu Học thuật chưa đụng tới');

        $this->actingAs($this->lead)->get(route('kpi.mine', ['period' => '2026-10', 'track' => 'teaching']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Kpi/Mine')->where('track', 'teaching')
                ->has('trackOptions', 2)->where('grade.grade', 'A')->where('statusLabel', 'Đã duyệt'));
        $this->actingAs($this->lead)->get(route('kpi.mine', ['period' => '2026-10']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('track', 'main')->where('roleLabel', fn ($label) => $label !== 'KPI giảng dạy (kiêm nhiệm)'));

        // Phiếu lương kiêm nhiệm hiện xếp loại phiếu KPI giảng dạy.
        $period = PayrollPeriod::create([
            'code' => 'PR-2026-10', 'title' => 'Bảng lương Tháng 10/2026', 'month' => 10, 'year' => 2026,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft',
        ]);
        $period->calculatePayrollForPeriod();
        $record = PayrollRecord::where('payroll_period_id', $period->id)->where('user_id', $this->lead->id)->firstOrFail();
        $this->actingAs($this->admin)->get(route('payroll.records.show', $record->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('teachingShare.kpi_sheet.grade', 'A · Xuất sắc')
                ->where('teachingShare.kpi_sheet.status_label', 'Đã duyệt')
                ->where('teachingShare.kpi_sheet.rate', 97));
    }
}

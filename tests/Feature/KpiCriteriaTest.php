<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tiêu chí KPI Học vụ theo file Excel KPI: 15 tiêu chí đếm lỗi, 3 mức 100 / 50 / 0%, lỗi nghiêm trọng về 0. */
class KpiCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::create(['name' => 'Cơ sở KPI', 'code' => 'KP', 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->staff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->staff->assignRole(Roles::ACADEMIC_STAFF);
        $this->travelTo(now()->setDate(2026, 10, 5));
    }

    public function test_default_set_matches_the_excel_fund_and_groups(): void
    {
        $criteria = KpiCriterion::active()->ordered()->get();
        $this->assertCount(15, $criteria);
        $this->assertSame(6, $criteria->pluck('group_name')->unique()->count());
        $this->assertEquals(100, (float) $criteria->sum('weight'));
        $this->assertEquals(2000000, $criteria->sum(fn (KpiCriterion $c) => $c->fundAmount(2000000)));

        $byGroup = $criteria->groupBy('group_name')->map(fn ($rows) => $rows->sum(fn (KpiCriterion $c) => $c->fundAmount(2000000)));
        $this->assertEquals(
            ['Học phí & dữ liệu' => 500000, 'Học viên & phụ huynh' => 400000, 'Tuyển sinh & Truyền thông' => 400000, 'Báo cáo & tuân thủ quy trình' => 300000, 'Vận hành lớp học' => 300000, 'Giáo viên & phối hợp' => 100000],
            $byGroup->all()
        );

        $first = $criteria->firstWhere('code', '1.1');
        $this->assertSame(250000.0, $first->fundAmount(2000000));
        $this->assertSame(['0 hồ sơ', '≤ 2 hồ sơ'], [$first->threshold_full, $first->threshold_half]);
        $sla = $criteria->firstWhere('code', '2.1');
        $this->assertSame([4, 10, '≤ 4 case'], [$sla->max_full, $sla->max_half, $sla->threshold_full]);
    }

    public function test_level_is_three_steps_by_error_count(): void
    {
        $sla = KpiCriterion::where('code', '2.1')->firstOrFail();   // ≤ 4 → 100%, ≤ 10 → 50%
        $this->assertSame([100, 100, 50, 50, 0], [$sla->levelForCount(0), $sla->levelForCount(4), $sla->levelForCount(5), $sla->levelForCount(10), $sla->levelForCount(11)]);

        $manual = KpiCriterion::create(['name' => 'Tự do', 'weight' => 1, 'is_active' => true]);
        $this->assertNull($manual->levelForCount(3));
    }

    public function test_evaluation_derives_levels_from_actual_counts_and_critical_error_zeroes_the_item(): void
    {
        $criteria = KpiCriterion::active()->ordered()->get()->keyBy('code');
        $actual = $criteria->map(fn () => '0')->mapWithKeys(fn ($v, $code) => [$criteria[$code]->id => $v])->all();
        $actual[$criteria['1.1']->id] = '2';    // ≤ 2 → 50%
        $actual[$criteria['1.2']->id] = '3';    // > 1 → 0%
        $actual[$criteria['2.1']->id] = '4';    // ≤ 4 → 100%
        $tampered = $criteria->mapWithKeys(fn ($c) => [$c->id => 100])->all();   // % gửi lên bị bỏ qua với tiêu chí đếm lỗi

        $this->actingAs($this->admin)->post(route('kpi.evaluate.store', $this->staff->id), [
            'month' => 9, 'year' => 2026, 'score' => $tampered, 'actual' => $actual,
            'critical' => [$criteria['3.1']->id => 1],
        ])->assertSessionHasNoErrors();

        $evaluation = KpiEvaluation::where('user_id', $this->staff->id)->firstOrFail();
        $score = fn (string $code) => (float) $evaluation->items()->where('kpi_criterion_id', $criteria[$code]->id)->value('score');
        $this->assertSame([50.0, 0.0, 100.0, 0.0, 100.0], [$score('1.1'), $score('1.2'), $score('2.1'), $score('3.1'), $score('6.1')]);
        $this->assertTrue((bool) $evaluation->items()->where('kpi_criterion_id', $criteria['3.1']->id)->value('critical_error'));
        // 100 − 6,25 (1.1 một nửa) − 7,5 (1.2) − 10 (3.1 nghiêm trọng)
        $this->assertEquals(76.25, (float) $evaluation->total_score);
    }

    public function test_criteria_screen_is_named_tieu_chi_kpi_and_saves_numeric_thresholds(): void
    {
        $this->actingAs($this->admin)->get(route('kpi.criteria'))
            ->assertOk()->assertSee('Tiêu chí KPI')->assertSee('Quy trình nhắc &amp; follow học phí đúng hạn', false);
        $menu = json_encode(app(\App\Support\Navigation\SidebarMenu::class)->settingsFor($this->admin), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"Tiêu chí KPI"', $menu);
        $this->assertStringNotContainsString('Tiêu chí KPI học vụ', $menu);

        $this->actingAs($this->admin)->get(route('kpi.evaluate', ['userId' => $this->staff->id, 'period' => '2026-09']))
            ->assertOk()->assertSee('Không có lỗi: điền 0 vào mục chưa nhập')->assertSee('Quy trình nhắc &amp; follow học phí đúng hạn', false);

        $criterion = KpiCriterion::where('code', '5.2')->firstOrFail();
        $this->actingAs($this->admin)->put(route('kpi.criteria.update', $criterion->id), [
            'name' => $criterion->name, 'weight' => 5, 'unit' => 'sai sót', 'max_full' => 1, 'max_half' => 6, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $criterion->refresh();
        $this->assertSame([1, 6, '≤ 1 sai sót', '≤ 6 sai sót'], [$criterion->max_full, $criterion->max_half, $criterion->threshold_full, $criterion->threshold_half]);

        // Ngưỡng 50% không được nhỏ hơn ngưỡng 100%, và phải nhập đủ cả hai.
        $this->actingAs($this->admin)->put(route('kpi.criteria.update', $criterion->id), [
            'name' => $criterion->name, 'weight' => 5, 'max_full' => 5, 'max_half' => 2,
        ])->assertSessionHasErrors('max_half');
        $this->actingAs($this->admin)->put(route('kpi.criteria.update', $criterion->id), [
            'name' => $criterion->name, 'weight' => 5, 'max_full' => 5,
        ])->assertSessionHasErrors('max_half');
    }
}

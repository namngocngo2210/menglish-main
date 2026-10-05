<?php

namespace Tests\Feature;

use App\Models\KpiCriterion;
use App\Models\KpiEvaluation;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollFormulaService;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Audit lần 2: lỗi lương sau khi đổi 9 vai trò cố định và bộ 15 tiêu chí KPI Học vụ. */
class AuditRound2PayrollFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_kpi_scored_against_retired_criteria_keeps_its_amount(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Roles::ACADEMIC_STAFF);
        $old = KpiCriterion::create(['code' => '9.1', 'name' => 'Tiêu chí cũ', 'group_name' => 'Cũ', 'weight' => 10, 'is_active' => true, 'sort_order' => 99]);
        $evaluation = KpiEvaluation::create(['user_id' => $staff->id, 'month' => 9, 'year' => 2026, 'total_score' => 80, 'status' => 'confirmed']);
        $evaluation->items()->create(['kpi_criterion_id' => $old->id, 'score' => 80]);
        // Đổi sang bộ tiêu chí mới: bộ cũ bị xóa mềm.
        $old->delete();

        $kpi = app(PayrollFormulaService::class)->academicKpiFor($staff, 9, 2026);
        $fund = (float) PayrollPeriod::payrollSettings()['academic_kpi_fund'];

        $this->assertEquals(80, $kpi['score']);
        $this->assertEquals(round($fund * 0.8), $kpi['amount']);
        $this->assertSame(['9.1'], array_column($kpi['items'], 'code'));
        $this->assertEquals(round($fund * 0.8), $kpi['items'][0]['amount']);
    }

    public function test_full_time_teacher_without_base_salary_moves_back_to_part_time(): void
    {
        $exPartTime = User::factory()->create(['base_salary' => 0, 'contract_type' => 'Cộng tác viên']);
        $exPartTime->assignRole(Roles::TEACHER_FULLTIME);
        $realFullTime = User::factory()->create(['base_salary' => 8000000, 'contract_type' => 'Toàn thời gian']);
        $realFullTime->assignRole(Roles::TEACHER_FULLTIME);
        Permission::query()->where('name', Rbac::assignRolePermission(Roles::TEACHER_PARTTIME))->delete();

        (require database_path('migrations/2026_10_31_090000_fix_retired_teacher_role_and_role_permissions.php'))->up();

        $this->assertSame([Roles::TEACHER_PARTTIME], $exPartTime->fresh()->getRoleNames()->all());
        $this->assertSame([Roles::TEACHER_FULLTIME], $realFullTime->fresh()->getRoleNames()->all());
        $this->assertTrue(Permission::query()->where('name', Rbac::assignRolePermission(Roles::TEACHER_PARTTIME))->exists());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CandidateCv;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\JobPosting;
use App\Models\Promotion;
use App\Models\StaffReport;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rà soát 19/10/2026: Quản lý cơ sở chỉ thấy dữ liệu chi nhánh mình ở dashboard Báo cáo / Nhật ký sự vụ, Tổng hợp báo cáo,
 * Tuyển dụng, Ưu đãi và màn Chốt & Xếp lớp; Admin / Học thuật (class.scope_all) vẫn thấy mọi cơ sở.
 */
class BranchReportScopeLeaksTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private User $managerA;

    private ClassModel $classA;

    private ClassModel $classB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'Cơ sở A', 'code' => 'CSA', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Cơ sở B', 'code' => 'CSB', 'is_active' => true]);
        $this->admin = $this->userWithRole(Roles::ADMIN, $this->branchA);
        $this->managerA = $this->userWithRole(Roles::MANAGER, $this->branchA);

        $this->classA = ClassModel::create(['code' => 'LOP-A', 'name' => 'Lớp A', 'branch_id' => $this->branchA->id, 'max_capacity' => 10, 'status' => 'active']);
        $this->classB = ClassModel::create(['code' => 'LOP-B', 'name' => 'Lớp B', 'branch_id' => $this->branchB->id, 'max_capacity' => 10, 'status' => 'active']);
    }

    private function userWithRole(string $role, ?Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** Báo cáo ngày + nhật ký sự vụ + báo cáo trực lớp của nhân sự hai chi nhánh. */
    private function seedReports(): void
    {
        foreach (['A' => [$this->branchA, $this->classA], 'B' => [$this->branchB, $this->classB]] as $key => [$branch, $class]) {
            $staff = $this->userWithRole(Roles::ACADEMIC_STAFF, $branch);
            StaffReport::create(['user_id' => $staff->id, 'type' => 'daily', 'title' => "BC-{$key}", 'content' => 'Nội dung',
                'report_date' => today()->toDateString(), 'status' => 'submitted']);
            StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => "NK-{$key}", 'content' => 'Sự vụ',
                'severity' => 'urgent', 'report_date' => today()->toDateString(), 'status' => 'open',
                'class_id' => $key === 'B' ? $class->id : null]);
            ClassReport::create(['class_id' => $class->id, 'reporter_id' => $staff->id, 'session_name' => "Buổi {$key}",
                'session_date' => today()->toDateString(), 'topics_learned' => 'Unit 1']);
        }
    }

    public function test_manager_sees_only_own_branch_on_academic_report_dashboard(): void
    {
        $this->seedReports();

        $this->actingAs($this->managerA)->get(route('academic.dashboards.reports', ['tab' => 'daily']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('staffDailyReports', fn ($rows) => collect($rows)->pluck('title')->all() === ['BC-A'])
                ->where('classReports', fn ($rows) => collect($rows)->pluck('class_name')->all() === ['Lớp A'])
                ->where('totalClasses', 1)
                ->where('totalDailyReportsToday', 2));

        foreach ([$this->admin, $this->userWithRole(Roles::ACADEMIC_LEAD, $this->branchA)] as $viewer) {
            $this->actingAs($viewer)->get(route('academic.reports', ['tab' => 'daily']))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('staffDailyReports', fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['BC-A', 'BC-B'])
                    ->where('totalClasses', 2));
        }
    }

    public function test_manager_sees_only_own_branch_journals_and_branch_param_is_ignored(): void
    {
        $this->seedReports();

        $titles = fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all();
        $this->actingAs($this->managerA)->get(route('academic.dashboards.incidents', ['branch_id' => $this->branchB->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('journals', fn ($rows) => $titles($rows) === ['NK-A'])
                ->where('branchId', '')
                ->where('branches', fn ($rows) => collect($rows)->pluck('value')->all() === [$this->branchA->id]));

        $this->actingAs($this->admin)->get(route('academic.incidents'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('journals', fn ($rows) => $titles($rows) === ['NK-A', 'NK-B']));
        $this->actingAs($this->admin)->get(route('academic.incidents', ['branch_id' => $this->branchB->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('journals', fn ($rows) => $titles($rows) === ['NK-B']));
        $this->actingAs($this->userWithRole(Roles::ACADEMIC_LEAD, $this->branchA))->get(route('academic.incidents'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('journals', fn ($rows) => $titles($rows) === ['NK-A', 'NK-B']));
    }

    public function test_manager_without_branch_sees_no_reports_of_others(): void
    {
        $this->seedReports();
        $managerNoBranch = $this->userWithRole(Roles::MANAGER, null);

        $this->actingAs($managerNoBranch)->get(route('reports.all'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reports.data', [])
                ->where('stats.daily', 0)
                ->where('branches', []));

        $this->actingAs($this->admin)->get(route('reports.all'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.daily', 2));
    }

    public function test_recruitment_is_limited_to_manager_branch(): void
    {
        $jobA = JobPosting::create(['title' => 'Tin A', 'department' => 'HV', 'employment_type' => 'Full-time', 'description' => 'x', 'branch_id' => $this->branchA->id, 'is_active' => true]);
        $jobB = JobPosting::create(['title' => 'Tin B', 'department' => 'HV', 'employment_type' => 'Full-time', 'description' => 'x', 'branch_id' => $this->branchB->id, 'is_active' => true]);
        $jobAll = JobPosting::create(['title' => 'Tin chung', 'department' => 'HV', 'employment_type' => 'Full-time', 'description' => 'x', 'is_active' => true]);
        CandidateCv::create(['full_name' => 'Ứng viên A', 'email' => 'a@example.com', 'phone' => '0900000001', 'applying_position' => 'GV', 'branch_id' => $this->branchA->id, 'status' => 'pending']);
        $cvB = CandidateCv::create(['full_name' => 'Ứng viên B', 'email' => 'b@example.com', 'phone' => '0900000002', 'applying_position' => 'GV', 'branch_id' => $this->branchB->id, 'status' => 'pending']);

        $this->actingAs($this->managerA)->get(route('recruitment.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('jobs', fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['Tin A', 'Tin chung'])
                ->where('candidates.data', fn ($rows) => collect($rows)->pluck('full_name')->all() === ['Ứng viên A'])
                ->where('stats.totalCvs', 1)
                ->where('branches', fn ($rows) => collect($rows)->pluck('value')->all() === [$this->branchA->id]));

        $this->actingAs($this->managerA)->post(route('recruitment.jobs.toggle', $jobB->id))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('recruitment.jobs.toggle', $jobAll->id))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('recruitment.cv.update-status', $cvB->id), ['status' => 'rejected'])->assertNotFound();
        $this->assertTrue((bool) $jobB->fresh()->is_active);
        $this->assertSame('pending', $cvB->fresh()->status);
        $this->actingAs($this->managerA)->post(route('recruitment.jobs.toggle', $jobA->id))->assertRedirect();
        $this->assertFalse((bool) $jobA->fresh()->is_active);

        $job = ['title' => 'Tin mới', 'department' => 'HV', 'employment_type' => 'Full-time', 'description' => 'x'];
        $this->actingAs($this->managerA)->post(route('recruitment.jobs.store'), $job + ['branch_id' => $this->branchB->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($this->managerA)->post(route('recruitment.jobs.store'), $job)->assertSessionHasErrors('branch_id');
        $this->actingAs($this->managerA)->post(route('recruitment.jobs.store'), $job + ['branch_id' => $this->branchA->id])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('recruitment.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.totalCvs', 2));
        $this->actingAs($this->admin)->post(route('recruitment.cv.update-status', $cvB->id), ['status' => 'reviewing'])->assertRedirect();
    }

    public function test_promotions_are_limited_to_manager_branch(): void
    {
        $promo = fn (string $code, ?Branch $branch) => Promotion::create(['code' => $code, 'name' => $code, 'type' => 'fixed', 'value' => 100000,
            'branch_id' => $branch?->id, 'is_active' => true]);
        $promoA = $promo('UD-A', $this->branchA);
        $promoB = $promo('UD-B', $this->branchB);
        $promoAll = $promo('UD-ALL', null);

        $this->actingAs($this->managerA)->get(route('crm.promotions.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('promotions.data', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === ['UD-A', 'UD-ALL'])
                ->where('counts.catalog', 2));

        $this->actingAs($this->managerA)->get(route('crm.promotions.edit', $promoB))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('crm.promotions.toggle', $promoB))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('crm.promotions.toggle', $promoAll))->assertNotFound();
        $this->actingAs($this->managerA)->put(route('crm.promotions.update', $promoB), ['name' => 'X', 'type' => 'fixed', 'value' => 1])->assertNotFound();
        $this->assertTrue((bool) $promoB->fresh()->is_active);
        $this->actingAs($this->managerA)->post(route('crm.promotions.toggle', $promoA))->assertRedirect();
        $this->assertFalse((bool) $promoA->fresh()->is_active);

        $payload = ['name' => 'Ưu đãi mới', 'type' => 'fixed', 'value' => 50000];
        $this->actingAs($this->managerA)->postJson(route('crm.promotions.store'), $payload + ['branch_id' => $this->branchB->id])->assertJsonValidationErrors('branch_id');
        $this->actingAs($this->managerA)->postJson(route('crm.promotions.store'), $payload)->assertJsonValidationErrors('branch_id');
        $this->actingAs($this->managerA)->postJson(route('crm.promotions.store'), $payload + ['branch_id' => $this->branchA->id])->assertOk();
        $this->actingAs($this->managerA)->put(route('crm.promotions.update', $promoA), $payload + ['branch_id' => $this->branchB->id])->assertSessionHasErrors('branch_id');

        $this->actingAs($this->admin)->get(route('crm.promotions.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.catalog', 4));
        $this->actingAs($this->admin)->post(route('crm.promotions.toggle', $promoB))->assertRedirect();
    }

    public function test_closing_wizard_lists_only_classes_of_viewer_branches(): void
    {
        $classCodes = fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all();

        foreach ([$this->managerA, $this->userWithRole(Roles::ACADEMIC_STAFF, $this->branchA), $this->userWithRole(Roles::SALES_CONSULTANT, $this->branchA)] as $viewer) {
            $this->actingAs($viewer)->get(route('crm.closing-wizard'))->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('classes', fn ($rows) => $classCodes($rows) === ['LOP-A'])
                    ->where('branches', fn ($rows) => collect($rows)->pluck('value')->all() === [$this->branchA->id]));
        }

        $this->actingAs($this->admin)->get(route('crm.closing-wizard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('classes', fn ($rows) => $classCodes($rows) === ['LOP-A', 'LOP-B']));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Navigation\SidebarMenu;
use App\Support\Rbac;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bảng lương (06/10/2026): ngoài Admin, mọi người chỉ xem phiếu lương của chính mình. Admin xem phiếu của mọi nhân sự
 * và thấy ai chưa có phiếu trong kỳ (lý do), thay vì tưởng bảng lương thiếu người.
 */
class PayrollOwnScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $academicStaff;

    private User $teacher;

    private User $noSalary;

    private PayrollPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'Cơ sở Lương', 'code' => 'LG', 'is_active' => true]);
        $this->admin = $this->staff(Roles::ADMIN, 'Quản trị hệ thống', 20000000);
        $this->manager = $this->staff(Roles::MANAGER, 'QLCS Một', 12000000);
        $this->academicStaff = $this->staff(Roles::ACADEMIC_STAFF, 'Học Vụ Hai', 9000000);
        $this->teacher = $this->staff(Roles::TEACHER_FULLTIME, 'Giáo Viên Ba', 15000000);
        $this->noSalary = $this->staff(Roles::ACADEMIC_STAFF, 'Học Vụ Chưa Lương', 0);

        $start = Carbon::create(2026, 9, 1);
        $this->period = PayrollPeriod::create([
            'code' => 'PR-2026-09', 'title' => 'Bảng lương Tháng 9/2026', 'month' => 9, 'year' => 2026,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'draft',
        ]);
        $this->period->calculatePayrollForPeriod();
    }

    private function staff(string $role, string $name, int $baseSalary): User
    {
        $user = User::factory()->create([
            'name' => $name, 'branch_id' => $this->branch->id, 'is_active' => true, 'base_salary' => $baseSalary,
            'created_at' => '2026-08-01 08:00:00',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function recordOf(User $user): PayrollRecord
    {
        return PayrollRecord::where('payroll_period_id', $this->period->id)->where('user_id', $user->id)->firstOrFail();
    }

    public function test_admin_sees_every_payslip_and_staff_missing_one(): void
    {
        $this->assertSame(4, $this->period->records()->count());

        $this->actingAs($this->admin)->get(route('payroll.periods.show', $this->period->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('records.total', 4)
                ->where('stats.records', 4)
                ->where('missingStaff.count', 1)
                ->where('missingStaff.items.0.name', 'Học Vụ Chưa Lương')
                ->where('missingStaff.items.0.reason', 'Chưa nhập lương cơ bản'));

        $this->actingAs($this->admin)->get(route('payroll.records.show', $this->recordOf($this->teacher)->id))->assertOk();
    }

    public function test_manager_only_reaches_own_payslip(): void
    {
        foreach (['payroll.periods.index' => [], 'payroll.periods.show' => [$this->period->id], 'payroll.periods.export' => [$this->period->id],
            'payroll.periods.fulltime' => [$this->period->id], 'payroll.periods.operations' => [$this->period->id]] as $name => $params) {
            $this->actingAs($this->manager)->get(route($name, $params))->assertForbidden();
        }
        // Mở thẳng phiếu lương của người khác (và cả của mình qua màn quản trị): bị chặn.
        $this->actingAs($this->manager)->get(route('payroll.records.show', $this->recordOf($this->admin)->id))->assertForbidden();
        $this->actingAs($this->manager)->get(route('payroll.records.show', $this->recordOf($this->teacher)->id))->assertForbidden();

        // Lương cơ bản của nhân sự khác trên hồ sơ cũng là thông tin lương.
        $this->actingAs($this->manager)->get(route('users.show', $this->teacher))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewSensitive', false));

        $menu = collect(app(SidebarMenu::class)->groupsFor($this->manager->fresh()))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'));
        $this->assertNotContains('payroll.periods.index', $menu);
        $this->assertContains('portal.my-salary', $menu);

        // "Lương của tôi" hiện đúng phiếu của QLCS khi kỳ đã chốt.
        $this->period->update(['status' => 'approved']);
        $this->actingAs($this->manager)->get(route('portal.my-salary'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('record.id', $this->recordOf($this->manager)->id));
    }

    public function test_payroll_view_granted_to_non_admin_still_shows_only_own_row(): void
    {
        // Admin cấp lại "Xem bảng lương" cho Quản lý cơ sở nhưng phạm vi vẫn "Của tôi": chỉ thấy dòng của mình.
        Role::findByName(Roles::MANAGER, 'web')->givePermissionTo('payroll.view');
        Rbac::flushCache();
        $manager = $this->manager->fresh();

        $this->actingAs($manager)->get(route('payroll.periods.show', $this->period->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('records.total', 1)
                ->where('records.data.0.user_id', $manager->id)
                ->where('stats.records', 1)
                ->where('stats.total_amount', fn ($amount) => (float) $amount === (float) $this->recordOf($manager)->net_salary)
                ->where('missingStaff', null));
        $this->actingAs($manager)->get(route('payroll.records.show', $this->recordOf($this->admin)->id))->assertNotFound();
        $this->actingAs($manager)->get(route('payroll.records.show', $this->recordOf($manager)->id))->assertOk();

        $csv = $this->actingAs($manager)->get(route('payroll.periods.export', [$this->period->id, 'format' => 'csv']));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('QLCS Một', $content);
        $this->assertStringNotContainsString('Giáo Viên Ba', $content);
        $this->assertStringNotContainsString('Quản trị hệ thống', $content);
    }

    public function test_migration_revokes_other_payslips_from_existing_roles(): void
    {
        // Hệ thống đang chạy: Quản lý cơ sở còn quyền cũ (xem bảng lương, mọi phiếu), Học vụ được cấp riêng "Chi nhánh".
        $manager = Role::findByName(Roles::MANAGER, 'web');
        $manager->revokePermissionTo('payroll.scope_own');
        $manager->givePermissionTo(['payroll.view', 'payroll.scope_all']);
        $this->academicStaff->givePermissionTo('payroll.scope_branch');
        Rbac::flushCache();
        $this->assertTrue($this->manager->fresh()->can('payroll.scope_all'));

        (require database_path('migrations/2026_11_01_090000_payroll_only_own_for_non_admin.php'))->up();

        $manager->refresh();
        $this->assertFalse($manager->hasPermissionTo('payroll.view'));
        $this->assertFalse($manager->hasPermissionTo('payroll.scope_all'));
        $this->assertTrue($manager->hasPermissionTo('payroll.view_own'));
        $this->assertTrue($manager->hasPermissionTo('payroll.scope_own'));
        $this->assertFalse($this->academicStaff->fresh()->can('payroll.scope_branch'));
        $this->assertTrue($this->admin->fresh()->can('payroll.scope_all'));

        $this->actingAs($this->manager->fresh())->get(route('payroll.periods.show', $this->period->id))->assertForbidden();
        $this->actingAs($this->admin->fresh())->get(route('payroll.periods.show', $this->period->id))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('records.total', 4));
    }
}

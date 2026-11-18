<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\OperatingExpense;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** TK-2026-0153: view-only finance users, Admin-only salary row, independent ledger / revenue-report permissions. */
class FinancePermissionsSplitTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'CN Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = $this->userWith(null, 'admin');

        $period = PayrollPeriod::create([
            'code' => 'PR-0153', 'title' => 'Lương 0153', 'month' => (int) now()->format('m'), 'year' => (int) now()->format('Y'),
            'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(),
            'status' => PayrollPeriod::LOCKED_STATUSES[0], 'total_staff' => 1, 'total_amount' => 9182736,
        ]);
        PayrollRecord::create(['payroll_period_id' => $period->id, 'user_id' => $this->admin->id, 'net_salary' => 9182736]);
    }

    /** User with a custom role holding exactly $permissions (or a built-in role when $builtin is given). */
    private function userWith(?array $permissions, ?string $builtin = null): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        if ($builtin) {
            $user->assignRole($builtin);

            return $user;
        }
        $role = Role::findOrCreate('custom_'.uniqid(), 'web');
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function expensePayload(): array
    {
        return [
            'expense_date' => now()->toDateString(), 'title' => 'Tiền điện', 'amount' => 500000,
            'payment_method' => 'tien_mat', 'is_vat_invoice' => 0, 'branch_id' => $this->branch->id,
        ];
    }

    public function test_view_only_user_cannot_create_update_or_delete_expenses(): void
    {
        $viewer = $this->userWith(['finance.view']);
        $expense = OperatingExpense::create([
            'expense_date' => now()->toDateString(), 'title' => 'Có sẵn', 'amount' => 1000, 'payment_method' => 'tien_mat',
            'branch_id' => $this->branch->id, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($viewer)->post(route('finance.expenses.store'), $this->expensePayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('finance.expenses.update', $expense->id), $this->expensePayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('finance.expenses.destroy', $expense->id))->assertForbidden();
        $this->assertDatabaseHas('operating_expenses', ['id' => $expense->id, 'title' => 'Có sẵn']);
        $this->assertSame(1, OperatingExpense::count());

        $this->actingAs($viewer)->get(route('finance.expenses.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
    }

    public function test_manage_permission_allows_expense_writes(): void
    {
        $manager = $this->userWith(['finance.view', 'finance.manage']);

        $this->actingAs($manager)->post(route('finance.expenses.store'), $this->expensePayload())->assertRedirect();
        $this->assertSame(1, OperatingExpense::count());
        $this->actingAs($manager)->get(route('finance.expenses.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true));
    }

    public function test_ledger_and_revenue_report_are_independent_permissions(): void
    {
        $ledgerOnly = $this->userWith(['finance.view']);
        $reportOnly = $this->userWith(['finance.view_revenue_report']);

        $this->actingAs($ledgerOnly)->get(route('finance.expenses.index'))->assertOk();
        $this->actingAs($ledgerOnly)->get(route('finance.expenses.export'))->assertOk();
        $this->actingAs($ledgerOnly)->get(route('finance.reports.revenue'))->assertForbidden();
        $this->actingAs($ledgerOnly)->get(route('finance.reports.revenue.export'))->assertForbidden();

        $this->actingAs($reportOnly)->get(route('finance.reports.revenue'))->assertOk();
        $this->actingAs($reportOnly)->get(route('finance.reports.revenue.export'))->assertOk();
        $this->actingAs($reportOnly)->get(route('finance.expenses.index'))->assertForbidden();
        $this->actingAs($reportOnly)->get(route('finance.expenses.export'))->assertForbidden();
    }

    public function test_salary_is_omitted_from_payloads_for_non_admin(): void
    {
        $viewer = $this->userWith(['finance.view', 'finance.view_revenue_report', 'finance.manage']);

        $this->actingAs($viewer)->get(route('finance.expenses.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('salaryHidden', true)
                ->where('autoSalaryRow', null)
                ->where('autoSalaryAmount', 0)
                ->where('grandTotalExpense', 0));

        $this->get(route('finance.reports.revenue'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('salaryHidden', true)
                ->missing('salaryTotal')
                ->missing('salaryPercentOfExpense')
                ->where('totalExpense', 0)
                ->where('branchMatrix.0.expense', 0));
        $this->assertStringNotContainsString('9.182.736', $this->get(route('finance.reports.revenue.export'))->streamedContent());
        $this->assertStringNotContainsString('9182736', $this->get(route('finance.reports.revenue'))->getContent());
    }

    public function test_admin_still_sees_salary(): void
    {
        $this->actingAs($this->admin)->get(route('finance.expenses.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('salaryHidden', false)
                ->where('autoSalaryAmount', fn ($v) => (float) $v === 9182736.0));
        $this->get(route('finance.reports.revenue'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('salaryTotal', fn ($v) => (float) $v === 9182736.0));
    }

    public function test_migration_preserves_existing_access(): void
    {
        $viewOnly = Role::findOrCreate('old_view_only', 'web');
        $viewOnly->givePermissionTo(['finance.view']);
        $bookkeeper = Role::findOrCreate('old_bookkeeper', 'web');
        $bookkeeper->givePermissionTo(['finance.view', 'tuition.create']);
        $unrelated = Role::findOrCreate('old_unrelated', 'web');
        $unrelated->givePermissionTo(['tuition.create']);
        $overrideUser = User::factory()->create(['branch_id' => $this->branch->id]);
        UserPermissionOverride::create([
            'user_id' => $overrideUser->id, 'module' => 'finance', 'action' => 'view', 'allow' => true,
            'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null, 'created_by' => null,
        ]);
        Role::query()->each(fn (Role $r) => $r->revokePermissionTo(['finance.manage', 'finance.view_revenue_report']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require base_path('database/migrations/2026_12_18_090000_split_finance_permissions.php'))->up();

        $this->assertTrue($viewOnly->fresh()->hasPermissionTo('finance.view_revenue_report'));
        $this->assertFalse($viewOnly->fresh()->hasPermissionTo('finance.manage'), 'view-only không được cấp manage');
        $this->assertTrue($bookkeeper->fresh()->hasPermissionTo('finance.manage'));
        $this->assertTrue($bookkeeper->fresh()->hasPermissionTo('finance.view_revenue_report'));
        $this->assertFalse($unrelated->fresh()->hasPermissionTo('finance.view_revenue_report'));
        $this->assertTrue(UserPermissionOverride::where('user_id', $overrideUser->id)
            ->where('module', 'finance')->where('action', 'view_revenue_report')->where('allow', true)->exists());
    }

    public function test_default_roles_keep_expense_management_but_salary_stays_admin_only(): void
    {
        foreach (['manager', 'accountant'] as $name) {
            $role = Role::findByName($name, 'web');
            $this->assertTrue($role->hasPermissionTo('finance.manage'), $name);
            $this->assertTrue($role->hasPermissionTo('finance.view_revenue_report'), $name);
            $this->assertFalse($role->hasPermissionTo('payroll.view'), $name);
        }
        $this->assertTrue(Permission::where('name', 'finance.manage')->exists());
    }
}

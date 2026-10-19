<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Penalty;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ranh giới dữ liệu theo vai trò: Quản lý cơ sở chỉ thấy chi nhánh của mình (Big Test, KPI, ticket, nhật ký, biên bản);
 * Admin thấy tất cả; tài khoản cá nhân chỉ thấy dữ liệu của mình; cấu hình email nhận thông báo vận hành chỉ Admin.
 */
class RoleDataScopeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $admin;

    private User $managerA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branchA = Branch::create(['name' => 'CN A', 'code' => 'BND-A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'CN B', 'code' => 'BND-B', 'is_active' => true]);
        $this->admin = $this->makeUser(Roles::ADMIN, $this->branchA);
        $this->managerA = $this->makeUser(Roles::MANAGER, $this->branchA);
    }

    // ── Phạm vi mặc định ─────────────────────────────────────────────────────────────────────

    public function test_manager_default_scopes_are_branch_for_big_test_kpi_ticket_activity_log(): void
    {
        foreach (['big_test', 'kpi', 'support_ticket', 'activity_log', 'violation'] as $module) {
            $this->assertSame(DataScope::BRANCH, DataScope::level($this->managerA, $module), $module);
        }
        $staff = $this->makeUser(Roles::ACADEMIC_STAFF, $this->branchA);
        foreach (['class', 'big_test', 'attendance_staff', 'kpi', 'work_task', 'violation'] as $module) {
            $this->assertSame(DataScope::BRANCH, DataScope::level($staff, $module), "academic_staff / {$module}");
        }
        $lead = $this->makeUser(Roles::ACADEMIC_LEAD, $this->branchA);
        foreach (['class', 'big_test', 'kpi', 'work_task', 'violation'] as $module) {
            $this->assertSame(DataScope::ALL, DataScope::level($lead, $module), "academic_lead / {$module}");
        }
    }

    // ── Ticket hỗ trợ ────────────────────────────────────────────────────────────────────────

    public function test_manager_sees_and_opens_only_tickets_of_own_branches(): void
    {
        $ticketA = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA), 'TK-A');
        $ticketB = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchB), 'TK-B');

        $codes = $this->ticketCodes($this->actingAs($this->managerA)->get(route('tickets.index')));
        $this->assertContains('TK-A', $codes);
        $this->assertNotContains('TK-B', $codes);

        $this->actingAs($this->managerA)->get(route('tickets.show', $ticketA->id))->assertOk();
        $this->actingAs($this->managerA)->get(route('tickets.show', $ticketB->id))->assertForbidden();
        $this->actingAs($this->managerA)->get(route('tickets.show', 'TK-B'))->assertForbidden();
        $this->actingAs($this->managerA)->post(route('tickets.messages.store', $ticketB->id), ['message' => 'x'])->assertForbidden();
        $this->actingAs($this->managerA)->post(route('tickets.status.update', $ticketB->id), ['status' => 'closed'])->assertForbidden();
        $this->actingAs($this->managerA)->post(route('tickets.assign', $ticketB->id), ['assignee_id' => $this->managerA->id])->assertForbidden();
        $this->assertSame('open', $ticketB->fresh()->status);
        $this->assertNull($ticketB->fresh()->assignee_id);
    }

    public function test_manager_cannot_assign_ticket_to_handler_of_another_branch(): void
    {
        $ticketA = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA), 'TK-A');
        $managerB = $this->makeUser(Roles::MANAGER, $this->branchB);

        $this->actingAs($this->managerA)->post(route('tickets.assign', $ticketA->id), ['assignee_id' => $managerB->id])
            ->assertSessionHasErrors('assignee_id');
        $this->actingAs($this->managerA)->post(route('tickets.assign', $ticketA->id), ['assignee_id' => $this->admin->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $ticketA->fresh()->assignee_id);
    }

    public function test_admin_sees_all_tickets_and_teacher_only_own(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA);
        $own = $this->ticket($teacher, 'TK-MINE');
        $other = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA), 'TK-OTHER');
        $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchB), 'TK-B');

        $adminCodes = $this->ticketCodes($this->actingAs($this->admin)->get(route('tickets.index')));
        $this->assertEqualsCanonicalizing(['TK-MINE', 'TK-OTHER', 'TK-B'], $adminCodes);

        $this->assertSame(['TK-MINE'], $this->ticketCodes($this->actingAs($teacher)->get(route('tickets.index'))));
        $this->actingAs($teacher)->get(route('tickets.show', $own->id))->assertOk();
        $this->actingAs($teacher)->get(route('tickets.show', $other->id))->assertForbidden();
    }

    // ── Biên bản vi phạm ─────────────────────────────────────────────────────────────────────

    public function test_manager_sees_and_acts_only_on_penalties_of_own_branches(): void
    {
        $teacherA = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA);
        $teacherB = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchB);
        $this->penalty($teacherA, 'BB-A');
        $penaltyB = $this->penalty($teacherB, 'BB-B');

        $response = $this->actingAs($this->managerA)->get(route('penalties.index'))->assertOk();
        $props = $response->viewData('page')['props'];
        $codes = collect($props['penalties']['data'])->pluck('code')->all();
        $this->assertContains('BB-A', $codes);
        $this->assertNotContains('BB-B', $codes);
        $this->assertSame(1, $props['counts']['pending']);
        $staffIds = collect($props['users'])->pluck('value')->all();
        $this->assertContains($teacherA->id, $staffIds);
        $this->assertNotContains($teacherB->id, $staffIds);

        $this->actingAs($this->managerA)->get(route('penalties.evidence', $penaltyB->id))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('penalties.confirm', $penaltyB->id), ['decision' => 'error'])->assertNotFound();
        $this->actingAs($this->managerA)->post(route('penalties.cancel', $penaltyB->id))->assertNotFound();
        $this->actingAs($this->managerA)->post(route('penalties.resolve', $penaltyB->id))->assertNotFound();
        $this->assertSame('pending', $penaltyB->fresh()->status);

        // Lập biên bản cho nhân sự chi nhánh khác: bị chặn khi lưu.
        $this->actingAs($this->managerA)->post(route('penalties.store'), [
            'user_id' => $teacherB->id, 'violation_type' => 'Vi phạm nội quy trung tâm', 'violation_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('user_id');
    }

    public function test_admin_and_academic_lead_see_all_penalties_teacher_only_own(): void
    {
        $teacherA = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA);
        $this->penalty($teacherA, 'BB-A');
        $this->penalty($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA), 'BB-A2');
        $this->penalty($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchB), 'BB-B');
        $lead = $this->makeUser(Roles::ACADEMIC_LEAD, $this->branchA);

        foreach ([$this->admin, $lead] as $viewer) {
            $this->assertEqualsCanonicalizing(['BB-A', 'BB-A2', 'BB-B'], $this->penaltyCodes($this->actingAs($viewer)->get(route('penalties.index'))));
        }
        $this->assertSame(['BB-A'], $this->penaltyCodes($this->actingAs($teacherA)->get(route('penalties.index'))));
    }

    // ── Email nhận thông báo vận hành ────────────────────────────────────────────────────────

    public function test_ticket_email_settings_are_admin_only(): void
    {
        $this->actingAs($this->managerA)->get(route('system-config.ticket-emails'))->assertForbidden();
        $this->actingAs($this->managerA)->post(route('system-config.ticket-emails.update'), ['emails' => ['outsider@example.com']])->assertForbidden();
        $this->actingAs($this->managerA)->post(route('system-config.ticket-emails.test'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('system-config.ticket-emails'))->assertOk();
    }

    // ── Migration dữ liệu cho hệ thống đang chạy ─────────────────────────────────────────────

    public function test_manager_migration_moves_scope_all_to_branch(): void
    {
        $role = Role::findByName(Roles::MANAGER, 'web');
        $modules = ['big_test', 'kpi', 'support_ticket', 'activity_log'];
        foreach ($modules as $module) {
            $role->revokePermissionTo(Permission::findOrCreate("{$module}.scope_branch", 'web'));
            $role->givePermissionTo(Permission::findOrCreate("{$module}.scope_all", 'web'));
        }
        // Module Admin đã hạ xuống "Của tôi" thì giữ nguyên.
        $role->revokePermissionTo(['kpi.scope_all']);
        $role->givePermissionTo(Permission::findOrCreate('kpi.scope_own', 'web'));
        Rbac::flushCache();
        $this->assertSame(DataScope::ALL, DataScope::level($this->managerA->fresh(), 'big_test'));

        $this->runMigration('2026_11_16_090000_limit_manager_data_scopes_to_branches.php');

        $manager = $this->managerA->fresh();
        foreach (['big_test', 'support_ticket', 'activity_log'] as $module) {
            $this->assertSame(DataScope::BRANCH, DataScope::level($manager, $module), $module);
            $this->assertFalse($role->fresh()->hasPermissionTo("{$module}.scope_all"), $module);
        }
        $this->assertSame(DataScope::OWN, DataScope::level($manager, 'kpi'));
    }

    public function test_academic_staff_migration_moves_scope_all_to_branch_and_keeps_academic_lead(): void
    {
        $role = Role::findByName(Roles::ACADEMIC_STAFF, 'web');
        $modules = ['class', 'big_test', 'attendance_staff', 'kpi', 'work_task'];
        foreach ($modules as $module) {
            $role->revokePermissionTo(Permission::findOrCreate("{$module}.scope_branch", 'web'));
            $role->givePermissionTo(Permission::findOrCreate("{$module}.scope_all", 'web'));
        }
        Rbac::flushCache();

        $this->runMigration('2026_11_16_090100_limit_academic_staff_data_scopes_to_branches.php');

        $staff = $this->makeUser(Roles::ACADEMIC_STAFF, $this->branchA);
        $lead = $this->makeUser(Roles::ACADEMIC_LEAD, $this->branchA);
        foreach ($modules as $module) {
            $this->assertSame(DataScope::BRANCH, DataScope::level($staff, $module), $module);
        }
        $this->assertSame(DataScope::ALL, DataScope::level($lead, 'class'));
    }

    public function test_violation_scope_migration_grants_defaults_and_keeps_custom_viewers(): void
    {
        $scopes = ['violation.scope_own', 'violation.scope_branch', 'violation.scope_all'];
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            $role->revokePermissionTo(array_filter($scopes, fn ($p) => $role->hasPermissionTo($p)));
        }
        $custom = Role::create(['name' => 'thanh_tra', 'guard_name' => 'web']);
        $custom->givePermissionTo('violation.view');
        $personal = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA);
        UserPermissionOverride::create(['user_id' => $personal->id, 'module' => 'violation', 'action' => 'view',
            'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null, 'allow' => true]);
        Rbac::flushCache();

        $this->runMigration('2026_11_16_090200_add_violation_data_scope.php');

        $this->assertSame(DataScope::BRANCH, DataScope::level($this->managerA->fresh(), 'violation'));
        $this->assertSame(DataScope::BRANCH, DataScope::level($this->makeUser(Roles::ACADEMIC_STAFF, $this->branchA), 'violation'));
        $this->assertSame(DataScope::ALL, DataScope::level($this->makeUser(Roles::ACADEMIC_LEAD, $this->branchA), 'violation'));
        $this->assertSame(DataScope::ALL, DataScope::level($this->makeUser('thanh_tra', $this->branchA), 'violation'));
        $this->assertSame(DataScope::ALL, DataScope::level($personal->fresh(), 'violation'));
        $this->assertSame(DataScope::OWN, DataScope::level($this->makeUser(Roles::TEACHER_PARTTIME, $this->branchA), 'violation'));
    }

    // ── Hỗ trợ ───────────────────────────────────────────────────────────────────────────────

    private function makeUser(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function ticket(User $creator, string $code): SupportTicket
    {
        return SupportTicket::create([
            'code' => $code, 'title' => "Ticket {$code}", 'category' => 'technical_issue', 'priority' => 'medium',
            'description' => 'Mô tả', 'creator_id' => $creator->id, 'status' => 'open',
        ]);
    }

    private function penalty(User $user, string $code): Penalty
    {
        return Penalty::create([
            'code' => $code, 'user_id' => $user->id, 'violation_type' => 'Vi phạm nội quy trung tâm',
            'error_category' => 'operations', 'violation_date' => today()->toDateString(), 'status' => 'pending',
        ]);
    }

    /** @return list<string> */
    private function ticketCodes(TestResponse $response): array
    {
        return collect($response->assertOk()->viewData('page')['props']['tickets']['data'])->pluck('code')->all();
    }

    /** @return list<string> */
    private function penaltyCodes(TestResponse $response): array
    {
        return collect($response->assertOk()->viewData('page')['props']['penalties']['data'])->pluck('code')->all();
    }

    private function runMigration(string $file): void
    {
        $migration = require database_path('migrations/'.$file);
        $migration->up();
        Rbac::flushCache();
    }
}

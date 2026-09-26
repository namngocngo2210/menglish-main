<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\User;
use App\Support\DataScope;
use App\Support\Navigation\SidebarMenu;
use App\Support\PermissionCatalog;
use App\Support\Rbac;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RBAC linh hoạt toàn hệ thống (docs/rbac.md): Admin cấu hình quyền + phạm vi dữ liệu cho mọi vai trò (kể cả Học vụ)
 * và từng người mà không sửa code; Super Admin bất biến; không tự khóa mình; mọi thay đổi có nhật ký.
 */
class RbacFlexibleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::create(['name' => 'CN RBAC', 'code' => 'RB', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'CN Ngoài', 'code' => 'RX', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
    }

    // ── Mặc định tái hiện đúng hành vi cũ ────────────────────────────────────────────────────

    public function test_default_data_scopes_per_role_match_previous_behaviour(): void
    {
        $expected = [
            'manager' => ['lead' => 'branch', 'student' => 'branch', 'class' => 'branch', 'big_test' => 'all', 'tuition' => 'branch', 'finance' => 'branch',
                'attendance_staff' => 'branch', 'payroll' => 'all', 'kpi' => 'all', 'work_task' => 'branch', 'support_ticket' => 'all', 'user' => 'branch',
                'activity_log' => 'all', 'dashboard' => 'branch'],
            'academic_staff' => ['lead' => 'branch', 'student' => 'branch', 'class' => 'all', 'big_test' => 'all', 'tuition' => 'branch',
                'attendance_staff' => 'all', 'kpi' => 'all', 'work_task' => 'all', 'support_ticket' => 'own', 'user' => 'own'],
            'academic_lead' => ['lead' => 'branch', 'student' => 'branch', 'class' => 'all', 'big_test' => 'all', 'kpi' => 'all', 'work_task' => 'all', 'user' => 'own'],
            'accountant' => ['student' => 'branch', 'tuition' => 'branch', 'finance' => 'branch', 'attendance_staff' => 'branch', 'payroll' => 'all', 'work_task' => 'own'],
            'sales_consultant' => ['lead' => 'own', 'student' => 'own', 'work_task' => 'own', 'support_ticket' => 'own'],
            'teacher' => ['class' => 'own', 'student' => 'own', 'big_test' => 'own', 'payroll' => 'own', 'work_task' => 'own'],
            'assistant' => ['class' => 'own', 'student' => 'own', 'big_test' => 'own', 'payroll' => 'own'],
            'student' => ['class' => 'own', 'support_ticket' => 'own'],
        ];
        foreach ($expected as $role => $modules) {
            $user = $this->makeUser($role);
            foreach ($modules as $module => $level) {
                $this->assertSame($level, DataScope::level($user, $module), "{$role} / {$module}");
            }
        }
        foreach (array_keys(PermissionCatalog::scopedModules()) as $module) {
            $this->assertSame('all', DataScope::level($this->admin, $module), "admin / {$module}");
        }
    }

    public function test_capability_permissions_replace_former_role_checks(): void
    {
        $can = fn (string $role, string $permission) => $this->makeUser($role)->can($permission);

        // A6 Q1: CM (Admin, Quản lý, Học vụ) chuyển tiến; chỉ Admin lùi bước.
        foreach (['manager', 'academic_staff'] as $role) {
            $this->assertTrue($can($role, 'lead.stage_forward'), $role);
            $this->assertFalse($can($role, 'lead.stage_back'), $role);
        }
        $this->assertFalse($can('sales_consultant', 'lead.stage_forward'));
        $this->assertTrue($this->admin->can('lead.stage_back'));
        // Hoàn tác nhật ký, bảng điều hành, báo cáo định kỳ, chốt biên bản theo loại lỗi.
        $this->assertFalse($can('manager', 'activity_log.undo'));
        $this->assertTrue($can('manager', 'dashboard.operations'));
        $this->assertTrue($can('academic_lead', 'dashboard.academic'));
        $this->assertTrue($can('teacher', 'staff_report.submit'));
        $this->assertFalse($can('accountant', 'staff_report.submit'));
        $this->assertTrue($can('academic_lead', 'violation.decide_academic'));
        $this->assertFalse($can('manager', 'violation.decide_academic'));
        $this->assertTrue($can('manager', 'violation.decide_operations'));
        // Quyền đối tượng: Super Admin KHÔNG tự có (không lọt vào cổng học viên / danh sách giáo viên).
        $this->assertFalse($this->admin->can('portal.student'));
        $this->assertFalse($this->admin->can('class.teach'));
        $this->assertTrue($this->admin->can('lead.be_assigned'));
        $this->assertTrue($can('student', 'portal.student'));
        $this->assertTrue($can('assistant', 'portal.assistant'));
        $this->assertFalse($can('assistant', 'portal.teacher'));
    }

    // ── Admin cấu hình vai trò (kể cả Học vụ) ─────────────────────────────────────────────────

    public function test_admin_revokes_permission_from_academic_staff_hides_menu_and_blocks_route(): void
    {
        $staff = $this->makeUser('academic_staff');
        $this->actingAs($staff)->get(route('kpi.monthly'))->assertOk();
        $this->assertContains('kpi.monthly', $this->menuRoutes($staff));

        $role = Role::findByName('academic_staff', 'web');
        $this->actingAs($this->admin)->put(route('roles.update', $role), [
            'name' => 'academic_staff',
            'matrix_submitted' => 1,
            'permissions' => $role->permissions->pluck('name')->reject(fn ($p) => $p === 'kpi.view')->values()->all(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('roles.index'));

        $staff = $staff->fresh();
        $this->assertNotContains('kpi.monthly', $this->menuRoutes($staff));
        $this->actingAs($staff)->get(route('kpi.monthly'))->assertForbidden();
        // Phạm vi dữ liệu và quyền khác giữ nguyên.
        $this->assertSame('branch', DataScope::level($staff, 'lead'));
        $this->actingAs($staff)->get(route('crm.customers.index'))->assertOk();

        $log = Activity::query()->where('log_name', 'Người dùng & Phân quyền')->latest('id')->first();
        $this->assertSame(['kpi.view'], $log->properties['removed']);
        $this->assertContains('kpi.view', $log->properties['old']['permissions']);
        $this->assertNotContains('kpi.view', $log->properties['attributes']['permissions']);
    }

    public function test_admin_creates_custom_role_and_its_permissions_and_scope_work(): void
    {
        $this->actingAs($this->admin)->get(route('roles.create'))->assertOk()
            ->assertSee('Phạm vi dữ liệu')->assertSee('Chuyển bước pipeline (tiến)')->assertSee('Được nhận phụ trách khách');

        $this->actingAs($this->admin)->post(route('roles.store'), [
            'name' => 'sale_online', 'label' => 'Sale online', 'description' => 'Tư vấn khách online',
            'permissions' => ['lead.view', 'lead.create', 'lead.update', 'lead.be_assigned', 'portal.staff', 'support_ticket.view', 'support_ticket.create'],
            'scope' => ['lead' => 'branch'],
        ])->assertSessionHasNoErrors();

        $role = Role::findByName('sale_online', 'web');
        $this->assertSame('Sale online', $role->label);
        $this->assertTrue($role->hasPermissionTo('lead.scope_branch'));
        // Quyền gán vai trò mới được tạo; Quản lý cơ sở (gán được mọi vai trò) nhận luôn.
        $this->assertTrue(Permission::where('name', 'user.assign_role.sale_online')->exists());
        $this->assertTrue(Role::findByName('manager', 'web')->hasPermissionTo('user.assign_role.sale_online'));
        $this->assertFalse(Role::findByName('academic_staff', 'web')->hasPermissionTo('user.assign_role.sale_online'));

        $online = $this->makeUser('sale_online', name: 'Sale Online A');
        $mine = $this->lead('KH Online Trong', ['assigned_user_id' => $this->admin->id]);
        $foreign = $this->lead('KH Online Ngoài', ['branch_id' => $this->otherBranch->id]);

        $this->actingAs($online)->get(route('crm.customers.index'))->assertOk()
            ->assertSee($mine->name)->assertDontSee($foreign->name);
        $this->assertContains('crm.customers.index', $this->menuRoutes($online));
        $this->assertContains('tickets.index', $this->menuRoutes($online));
        // Được nhận phụ trách khách.
        $sales = $this->makeUser('sales_consultant');
        $this->actingAs($this->makeUser('manager'))->post(route('crm.customers.reassign', $mine->id), [
            'assigned_user_id' => $online->id, 'reason' => 'Chuyển kênh online',
        ])->assertSessionHasNoErrors();
        $this->assertSame($online->id, $mine->fresh()->assigned_user_id);
        $this->assertNotNull($sales);
        // Không có quyền xóa.
        $this->actingAs($online)->delete(route('crm.customers.destroy', $mine->id))->assertForbidden();
    }

    public function test_clone_rename_and_delete_role(): void
    {
        $source = Role::findByName('academic_staff', 'web');
        $this->actingAs($this->admin)->post(route('roles.duplicate', $source))->assertRedirect();
        $copy = Role::findByName('academic_staff_copy', 'web');
        $this->assertEqualsCanonicalizing($source->permissions->pluck('name')->all(), $copy->permissions->pluck('name')->all());
        $this->assertSame('Bản sao của Học vụ', $copy->label);

        $this->actingAs($this->admin)->put(route('roles.update', $copy), ['name' => 'hoc_vu_2', 'label' => 'Học vụ ca tối'])->assertSessionHasNoErrors();
        $copy = $copy->fresh();
        $this->assertSame(['hoc_vu_2', 'Học vụ ca tối'], [$copy->name, $copy->label]);
        $this->assertEqualsCanonicalizing($source->permissions->pluck('name')->all(), $copy->permissions->pluck('name')->all(), 'Đổi tên không đổi quyền.');
        $this->assertTrue(Permission::where('name', 'user.assign_role.hoc_vu_2')->exists());
        $this->assertFalse(Permission::where('name', 'user.assign_role.academic_staff_copy')->exists());
        $this->assertSame('Học vụ ca tối', \App\Helpers\AclHelper::shortRoleLabel('hoc_vu_2'));

        // Vai trò hệ thống không đổi mã.
        $this->actingAs($this->admin)->put(route('roles.update', $source), ['name' => 'hoc_vu_moi'])->assertSessionHasErrors('name');

        $user = $this->makeUser('hoc_vu_2');
        $this->actingAs($this->admin)->delete(route('roles.destroy', $copy))->assertSessionHasErrors('role');
        $user->syncRoles(['academic_staff']);
        $this->actingAs($this->admin)->delete(route('roles.destroy', $copy))->assertSessionHasNoErrors();
        $this->assertFalse(Role::where('name', 'hoc_vu_2')->exists());
        $this->assertFalse(Permission::where('name', 'user.assign_role.hoc_vu_2')->exists());
    }

    // ── Phạm vi dữ liệu own → branch → all ─────────────────────────────────────────────────────

    public function test_scope_change_own_branch_all_changes_crm_student_and_tuition_lists(): void
    {
        $sales = $this->makeUser('sales_consultant', name: 'Sale Phạm Vi');
        $colleague = $this->makeUser('sales_consultant', name: 'Sale Đồng Nghiệp');
        $mine = $this->lead('KH Của Tôi', ['assigned_user_id' => $sales->id]);
        $branchLead = $this->lead('KH Nhánh Này', ['assigned_user_id' => $colleague->id]);
        $foreignLead = $this->lead('KH Nhánh Ngoài', ['assigned_user_id' => $colleague->id, 'branch_id' => $this->otherBranch->id]);

        $see = fn () => collect([$mine, $branchLead, $foreignLead])
            ->filter(fn (CrmCustomer $c) => str_contains($this->actingAs($sales->fresh())->get(route('crm.customers.index'))->getContent(), $c->name))
            ->map->name->values()->all();

        $this->assertSame(['KH Của Tôi'], $see());

        $role = Role::findByName('sales_consultant', 'web');
        foreach (['branch' => ['KH Của Tôi', 'KH Nhánh Này'], 'all' => ['KH Của Tôi', 'KH Nhánh Này', 'KH Nhánh Ngoài'], 'own' => ['KH Của Tôi']] as $level => $visible) {
            $this->actingAs($this->admin)->put(route('roles.update', $role), [
                'name' => 'sales_consultant', 'matrix_submitted' => 1,
                'permissions' => $role->fresh()->permissions->pluck('name')->all(),
                'scope' => ['lead' => $level],
            ])->assertSessionHasNoErrors();
            $this->assertSame($visible, $see(), "lead scope {$level}");
        }

        // Học viên & học phí: Kế toán (mặc định "Chi nhánh") → cá nhân "Toàn hệ thống" → cá nhân "Chi nhánh".
        $accountant = $this->makeUser('accountant', name: 'Kế toán Phạm Vi');
        $here = $this->student('HV-RB-TRONG', $this->branch);
        $there = $this->student('HV-RB-NGOAI', $this->otherBranch);
        $tuitionPage = fn () => $this->actingAs($accountant->fresh())->get(route('tuition.students'))->assertOk();
        $tuitionPage()->assertSee('HV-RB-TRONG')->assertDontSee('HV-RB-NGOAI');
        $this->assertSame([$here->id], Student::visibleTo($accountant->fresh())->pluck('id')->all());

        $this->actingAs($this->admin)->put(route('users.permissions.update', $accountant), [
            'data_scope' => ['tuition' => 'all', 'student' => 'all'],
        ])->assertSessionHasNoErrors();
        $tuitionPage()->assertSee('HV-RB-TRONG')->assertSee('HV-RB-NGOAI');
        $this->assertEqualsCanonicalizing([$here->id, $there->id], Student::visibleTo($accountant->fresh())->pluck('id')->all());

        $this->actingAs($this->admin)->put(route('users.permissions.update', $accountant), [
            'data_scope' => ['tuition' => 'inherit', 'student' => 'inherit'],
        ])->assertSessionHasNoErrors();
        $tuitionPage()->assertDontSee('HV-RB-NGOAI');

        // Học vụ: cá nhân hạ phạm vi học viên xuống "Của tôi" → chỉ học viên lớp mình phụ trách.
        $staff = $this->makeUser('academic_staff', name: 'Học vụ Phạm Vi');
        $this->actingAs($staff)->get(route('students.index'))->assertOk()->assertSee('HV-RB-TRONG');
        $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), ['data_scope' => ['student' => 'own']])->assertSessionHasNoErrors();
        $this->assertSame('own', DataScope::level($staff->fresh(), 'student'));
        $this->actingAs($staff->fresh())->get(route('students.index'))->assertOk()->assertDontSee('HV-RB-TRONG');
    }

    // ── Phân quyền cá nhân ────────────────────────────────────────────────────────────────────

    public function test_personal_deny_overrides_role_allow_and_personal_allow_extends(): void
    {
        $staff = $this->makeUser('academic_staff');
        $this->actingAs($staff)->get(route('crm.customers.index'))->assertOk();

        $this->actingAs($this->admin)->get(route('users.permissions.edit', $staff))->assertOk()
            ->assertSee('Phạm vi dữ liệu')->assertSee('Theo vai trò (Chi nhánh)', false);
        $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), [
            'overrides' => ['lead' => ['view' => 'deny'], 'user' => ['assign_role:manager' => 'allow']],
        ])->assertSessionHasNoErrors();

        $staff = $staff->fresh();
        $this->assertFalse($staff->can('lead.view'));
        $this->actingAs($staff)->get(route('crm.customers.index'))->assertForbidden();
        $this->assertNotContains('crm.customers.index', $this->menuRoutes($staff));
        // Quyền động gán vai trò (user.assign_role.<vai trò>) cấp theo người.
        $this->assertTrue(Rbac::canAssignRole($staff, 'manager'));

        $log = Activity::query()->where('log_name', 'Người dùng & Phân quyền')->latest('id')->first();
        $this->assertContains('lead.view=deny', $log->properties['attributes']['overrides']);
        $this->assertSame([], $log->properties['old']['overrides']);
    }

    // ── Phân cấp gán vai trò ──────────────────────────────────────────────────────────────────

    public function test_role_assignment_hierarchy_is_permission_based_and_super_admin_is_protected(): void
    {
        $staff = $this->makeUser('academic_staff');
        $this->assertEqualsCanonicalizing(['assistant', 'student', 'teacher', 'teacher_fulltime', 'teacher_parttime'], Rbac::assignableRoles($staff));
        $this->assertEqualsCanonicalizing(['teacher', 'teacher_fulltime', 'teacher_parttime'], Rbac::assignableRoles($this->makeUser('academic_lead')));
        $this->assertNotContains('admin', Rbac::assignableRoles($this->makeUser('manager')));
        $this->assertContains('admin', Rbac::assignableRoles($this->admin));

        $target = $this->makeUser('teacher');
        $this->actingAs($staff)->put(route('users.roles.update', $target), ['roles' => ['manager']])->assertSessionHasErrors('role');

        // Admin cấp cho vai trò Học vụ quyền gán "Quản lý cơ sở" trên màn Vai trò → làm được, không cần sửa code.
        $role = Role::findByName('academic_staff', 'web');
        $role->givePermissionTo('user.assign_role.manager');
        Rbac::flushCache();
        $this->actingAs($staff->fresh())->put(route('users.roles.update', $target), ['roles' => ['manager']])->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->roles->contains('name', 'manager'));

        // Super Admin chỉ Super Admin gán được — kể cả khi ai đó được cấp quyền (không tồn tại) user.assign_role.admin.
        \App\Models\UserPermissionOverride::create(['user_id' => $staff->id, 'module' => 'user', 'action' => 'assign_role.admin', 'allow' => true, 'scope_type' => 'all']);
        $this->assertFalse(Rbac::canAssignRole($staff->fresh(), 'admin'));
        $other = $this->makeUser('teacher');
        $this->actingAs($staff->fresh())->put(route('users.roles.update', $other), ['roles' => ['admin']])->assertSessionHasErrors('role');
        $this->actingAs($staff->fresh())->get(route('users.edit', $this->admin))->assertForbidden();
    }

    // ── An toàn ──────────────────────────────────────────────────────────────────────────────

    public function test_super_admin_role_is_immutable(): void
    {
        $role = Role::findByName('admin', 'web');
        $count = $role->permissions()->count();

        $this->actingAs($this->admin)->get(route('roles.edit', $role))->assertOk()->assertSee('Vai trò Super Admin bất biến');
        $this->actingAs($this->admin)->put(route('roles.update', $role), [
            'name' => 'admin', 'permissions' => ['lead.view'], 'matrix_submitted' => 1,
        ])->assertSessionHasErrors('permissions');
        $this->actingAs($this->admin)->put(route('roles.update', $role), ['name' => 'super_admin'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->delete(route('roles.destroy', $role))->assertSessionHasErrors('role');
        $this->assertSame($count, $role->fresh()->permissions()->count());
        $this->assertTrue(Role::where('name', 'admin')->exists());
        // Đổi tên hiển thị vẫn được.
        $this->actingAs($this->admin)->put(route('roles.update', $role), ['name' => 'admin', 'label' => 'Giám đốc hệ thống'])->assertSessionHasNoErrors();
        $this->assertSame('Giám đốc hệ thống', $role->fresh()->label);

        // Phân quyền cá nhân "chặn" không thu hẹp được Super Admin.
        $otherAdmin = $this->makeUser('admin');
        $this->actingAs($this->admin)->put(route('users.permissions.update', $otherAdmin), ['overrides' => ['lead' => ['view' => 'deny']]])->assertSessionHasNoErrors();
        $this->assertTrue($otherAdmin->fresh()->can('lead.view'));
        // Người không phải Super Admin không sửa quyền của Super Admin.
        $manager = $this->makeUser('manager');
        $manager->givePermissionTo('permission.override');
        $this->actingAs($manager)->get(route('users.permissions.edit', $otherAdmin))->assertForbidden();
    }

    public function test_user_cannot_lock_themselves_out_of_permission_management(): void
    {
        // Vai trò quản trị phân quyền tự tạo.
        $role = Role::create(['name' => 'rbac_officer', 'guard_name' => 'web']);
        $role->givePermissionTo(['role.view', 'role.update', 'role.assign_permission', 'user.view', 'user.assign_role', 'permission.override']);
        Rbac::registerRole($role);
        $officer = $this->makeUser('rbac_officer');

        // Bỏ quyền phân quyền khỏi vai trò mình đang giữ → bị chặn.
        $this->actingAs($officer)->put(route('roles.update', $role), [
            'name' => 'rbac_officer', 'matrix_submitted' => 1,
            'permissions' => ['role.view', 'role.update', 'user.view', 'user.assign_role', 'permission.override'],
        ])->assertSessionHasErrors('permissions');
        $this->assertTrue($role->fresh()->hasPermissionTo('role.assign_permission'));

        // Thêm quyền khác cho vai trò mình vẫn được.
        $this->actingAs($officer)->put(route('roles.update', $role), [
            'name' => 'rbac_officer', 'matrix_submitted' => 1,
            'permissions' => ['role.view', 'role.update', 'role.assign_permission', 'user.view', 'user.assign_role', 'permission.override', 'lead.view'],
        ])->assertSessionHasNoErrors();

        // Tự phân quyền cá nhân cho mình: chặn.
        $this->actingAs($officer)->get(route('users.permissions.edit', $officer))->assertForbidden();

        // Super Admin không tự bỏ vai trò Super Admin của mình.
        $this->actingAs($this->admin)->put(route('users.roles.update', $this->admin), ['roles' => ['manager']])->assertSessionHasErrors('roles');
        $this->assertTrue($this->admin->fresh()->isSuperAdmin());
        $this->actingAs($this->admin)->put(route('users.roles.update', $this->admin), ['roles' => ['admin', 'manager']])->assertSessionHasNoErrors();
    }

    // ── Cổng theo đối tượng ───────────────────────────────────────────────────────────────────

    public function test_portal_menus_follow_audience_permissions(): void
    {
        $groups = fn (User $u) => collect(app(SidebarMenu::class)->groupsFor($u->fresh()))->pluck('id')->all();

        $this->assertNotContains('student_portal', $groups($this->admin));
        $this->assertNotContains('teacher_schedule', $groups($this->admin));
        $this->assertContains('teacher_schedule', $groups($this->makeUser('teacher')));
        $this->assertContains('teacher_schedule', $groups($this->makeUser('assistant')));

        $staff = $this->makeUser('academic_staff');
        $this->assertNotContains('teacher_schedule', $groups($staff));
        $this->actingAs($this->admin)->put(route('users.permissions.update', $staff), ['overrides' => ['portal' => ['teacher' => 'allow']]])->assertSessionHasNoErrors();
        $this->assertContains('teacher_schedule', $groups($staff));
    }

    // ── Seed chỉ thêm, không ghi đè cấu hình Admin ────────────────────────────────────────────

    public function test_reseeding_never_overwrites_admin_customisations(): void
    {
        $role = Role::findByName('academic_staff', 'web');
        $role->revokePermissionTo('kpi.view');
        $role->givePermissionTo('lead.delete');
        Role::findByName('student', 'web')->delete();

        $this->runSeederForReal(PermissionSeeder::class);
        $this->runSeederForReal(RoleSeeder::class);

        $role = $role->fresh();
        $this->assertFalse($role->hasPermissionTo('kpi.view'), 'Seeder không cấp lại quyền Admin đã thu hồi.');
        $this->assertTrue($role->hasPermissionTo('lead.delete'), 'Seeder không thu hồi quyền Admin đã cấp.');
        $this->assertTrue(Role::where('name', 'student')->exists(), 'Vai trò thiếu được tạo lại với mặc định.');
        $this->assertTrue(Role::findByName('student', 'web')->hasPermissionTo('portal.student'));
        // Super Admin luôn đủ quyền.
        $this->assertEqualsCanonicalizing(Rbac::superAdminPermissions(), Role::findByName('admin', 'web')->permissions->pluck('name')
            ->reject(fn ($p) => PermissionCatalog::isAudience($p))->values()->all());
    }

    public function test_upgrade_migration_moves_all_branches_grants_from_roles_direct_and_overrides(): void
    {
        // Dữ liệu trước RBAC: quyền cũ *.all_branches gán theo vai trò tự tạo, trực tiếp và phân quyền cá nhân.
        Permission::findOrCreate('tuition.all_branches', 'web');
        Permission::findOrCreate('finance.all_branches', 'web');
        $headOffice = Role::create(['name' => 'head_office', 'guard_name' => 'web']);
        $headOffice->givePermissionTo('tuition.all_branches');
        $byRole = $this->makeUser('head_office');
        $direct = $this->makeUser('accountant');
        $direct->givePermissionTo('finance.all_branches');
        $personal = $this->makeUser('accountant');
        \App\Models\UserPermissionOverride::create(['user_id' => $personal->id, 'module' => 'tuition', 'action' => 'all_branches',
            'allow' => true, 'scope_type' => \App\Models\UserPermissionOverride::SCOPE_ALL, 'scope_id' => null]);
        $branchAccountant = $this->makeUser('accountant');
        Rbac::flushCache();

        $migration = require database_path('migrations/2026_10_07_100100_introduce_flexible_rbac_permissions.php');
        $migration->up();
        $migration->up(); // chạy lại an toàn

        $this->assertFalse(Permission::whereIn('name', ['tuition.all_branches', 'finance.all_branches'])->exists());
        $this->assertTrue($headOffice->fresh()->hasPermissionTo('tuition.scope_all'));
        $this->assertSame('all', DataScope::level($byRole->fresh(), 'tuition'));
        $this->assertSame('all', DataScope::level($byRole->fresh(), 'attendance_staff'), 'tuition.all_branches từng mở chấm công tay mọi lớp.');
        $this->assertSame('all', DataScope::level($direct->fresh(), 'finance'));
        $this->assertSame('branch', DataScope::level($direct->fresh(), 'tuition'));
        $this->assertSame('all', DataScope::level($personal->fresh(), 'tuition'));
        $this->assertSame('all', DataScope::level($personal->fresh(), 'attendance_staff'));
        foreach (['tuition', 'finance', 'attendance_staff'] as $module) {
            $this->assertSame('branch', DataScope::level($branchAccountant->fresh(), $module), $module);
        }
    }

    public function test_every_catalog_permission_has_vietnamese_label_and_description(): void
    {
        foreach (PermissionCatalog::staticPermissions() as $permission) {
            $this->assertNotSame($permission, PermissionCatalog::label($permission), "{$permission} thiếu nhãn");
            $this->assertNotSame('', trim(PermissionCatalog::description($permission)), "{$permission} thiếu mô tả");
        }
        $this->assertSame([], Permission::query()->pluck('name')
            ->reject(fn ($p) => PermissionCatalog::kind($p) !== null)->values()->all(), 'Permission trong DB không có trong danh mục.');
    }

    private function makeUser(string $role, ?Branch $branch = null, ?string $name = null): User
    {
        $user = User::factory()->create(array_filter(['branch_id' => ($branch ?? $this->branch)->id, 'is_active' => true, 'name' => $name]));
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> */
    private function menuRoutes(User $user): array
    {
        return collect(app(SidebarMenu::class)->groupsFor($user->fresh()))->flatMap(fn (array $g) => collect($g['items'])->pluck('route'))->all();
    }

    private function lead(string $name, array $attributes = []): CrmCustomer
    {
        return CrmCustomer::create($attributes + [
            'code' => CrmCustomer::generateCode(), 'name' => $name, 'phone' => '09'.random_int(10000000, 99999999),
            'branch_id' => $this->branch->id, 'stage' => 'new',
        ]);
    }

    private function student(string $code, Branch $branch): Student
    {
        $student = Student::create(['code' => $code, 'name' => 'Học viên '.$code, 'phone' => '09'.random_int(10000000, 99999999), 'branch_id' => $branch->id, 'status' => 'studying']);
        StudentTuition::create(['student_id' => $student->id, 'branch_id' => $branch->id, 'total_amount' => 3000000, 'final_amount' => 3000000,
            'paid_amount' => 0, 'debt_amount' => 3000000, 'due_date' => now()->addDays(5), 'status' => 'unpaid']);

        return $student;
    }
}

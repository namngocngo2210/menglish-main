<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AclRoleUserTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name' => 'Chi nhánh Cầu Giấy',
            'code' => 'CG',
            'address' => 'Số 10 Cầu Giấy, Hà Nội',
            'phone' => '0241234567',
            'is_active' => true,
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->adminUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('admin');
    }

    // =========================================================================
    // a. Permission creation, listing, slug uniqueness & validation
    // =========================================================================

    public function test_can_list_permissions(): void
    {
        Permission::firstOrCreate(['name' => 'aaa.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'aab.manage', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->get(route('permissions.index'));

        $response->assertOk();
        $response->assertViewIs('permissions.index');
        $response->assertSee('aaa.view');
        $response->assertSee('aab.manage');
    }

    public function test_can_create_permission_with_valid_module_action_slug(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('permissions.store'), [
            'name' => 'academic.grade_submission',
        ]);

        $response->assertRedirect(route('permissions.index'));
        $this->assertDatabaseHas('permissions', [
            'name' => 'academic.grade_submission',
            'guard_name' => 'web',
        ]);
    }

    public function test_permission_creation_fails_on_duplicate_slug(): void
    {
        Permission::firstOrCreate(['name' => 'crm.export', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->post(route('permissions.store'), [
            'name' => 'crm.export',
        ]);

        $response->assertSessionHasErrors(['name']);
        $this->assertCount(1, Permission::where('name', 'crm.export')->get());
    }

    public function test_permission_creation_fails_on_invalid_slug_format(): void
    {
        $invalidNames = ['CRM_VIEW', 'crm-view', 'crm.view.detail', 'crm view'];

        foreach ($invalidNames as $invalidName) {
            $response = $this->actingAs($this->adminUser)->post(route('permissions.store'), [
                'name' => $invalidName,
            ]);

            $response->assertSessionHasErrors(['name']);
        }
    }

    public function test_can_update_permission_name(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'payroll.view', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->put(route('permissions.update', $permission), [
            'name' => 'payroll.view_all',
        ]);

        $response->assertRedirect(route('permissions.index'));
        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'payroll.view_all',
        ]);
    }

    public function test_cannot_delete_permission_assigned_to_role(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'tuition.approve', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $response = $this->actingAs($this->adminUser)->delete(route('permissions.destroy', $permission));

        $response->assertSessionHasErrors(['permission']);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_can_delete_unassigned_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'test.unused_action', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->delete(route('permissions.destroy', $permission));

        $response->assertRedirect(route('permissions.index'));
        $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
    }

    // =========================================================================
    // b. Role creation and attaching permissions
    // =========================================================================

    public function test_can_list_roles(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'academic_lead', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->get(route('roles.index'));

        $response->assertOk();
        $response->assertViewIs('roles.index');
        $response->assertSee('super_admin');
        $response->assertSee('academic_lead');
    }

    public function test_can_create_role_and_attach_permissions(): void
    {
        $perm1 = Permission::firstOrCreate(['name' => 'crm.create', 'guard_name' => 'web']);
        $perm2 = Permission::firstOrCreate(['name' => 'crm.update', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->post(route('roles.store'), [
            'name' => 'test_sales_consultant',
            'permissions' => [$perm1->name, $perm2->name],
        ]);

        $response->assertRedirect(route('roles.index'));
        $this->assertDatabaseHas('roles', ['name' => 'test_sales_consultant']);

        $role = Role::where('name', 'test_sales_consultant')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('crm.create'));
        $this->assertTrue($role->hasPermissionTo('crm.update'));
    }

    public function test_role_creation_fails_on_duplicate_name(): void
    {
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->post(route('roles.store'), [
            'name' => 'teacher',
        ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_can_update_role_and_sync_permissions(): void
    {
        $role = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);
        $perm1 = Permission::firstOrCreate(['name' => 'syllabus.create', 'guard_name' => 'web']);
        $perm2 = Permission::firstOrCreate(['name' => 'syllabus.edit', 'guard_name' => 'web']);
        $role->givePermissionTo($perm1);

        $response = $this->actingAs($this->adminUser)->put(route('roles.update', $role), [
            'name' => 'senior_editor',
            'permissions' => [$perm2->name],
        ]);

        $response->assertRedirect(route('roles.index'));
        $role->refresh();
        $this->assertEquals('senior_editor', $role->name);
        $this->assertFalse($role->hasPermissionTo('syllabus.create'));
        $this->assertTrue($role->hasPermissionTo('syllabus.edit'));
    }

    public function test_cannot_delete_role_assigned_to_users(): void
    {
        $role = Role::firstOrCreate(['name' => 'branch_manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        $response = $this->actingAs($this->adminUser)->delete(route('roles.destroy', $role));

        $response->assertSessionHasErrors(['role']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    // =========================================================================
    // c. User creation (name, email, password hashing, branch_id, is_active)
    // =========================================================================

    public function test_can_create_user_with_full_fields_and_hashed_password(): void
    {
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);

        $payload = [
            'name' => 'Nguyễn Thị Hương',
            'email' => 'huong.nguyen@menglish.edu.vn',
            'employee_code' => 'NV-0099',
            'phone' => '0987654321',
            'branch_id' => $this->branch->id,
            'role' => 'teacher',
            'password' => 'SecurePass123!',
        ];

        $response = $this->actingAs($this->adminUser)->post(route('users.store'), $payload);

        $response->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'Nguyễn Thị Hương',
            'email' => 'huong.nguyen@menglish.edu.vn',
            'employee_code' => 'NV-0099',
            'phone' => '0987654321',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $createdUser = User::where('email', 'huong.nguyen@menglish.edu.vn')->first();
        $this->assertNotNull($createdUser);
        $this->assertTrue(Hash::check('SecurePass123!', $createdUser->password));
        $this->assertTrue($createdUser->hasRole('teacher'));
    }

    public function test_user_creation_validation_fails_on_duplicate_email_and_missing_branch(): void
    {
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        User::factory()->create(['email' => 'existing@menglish.edu.vn']);

        $response = $this->actingAs($this->adminUser)->post(route('users.store'), [
            'name' => 'Duplicate Email User',
            'email' => 'existing@menglish.edu.vn',
            'branch_id' => null,
            'role' => 'staff',
            'password' => '12345678',
        ]);

        $response->assertSessionHasErrors(['email', 'branch_id']);
    }

    public function test_user_creation_fails_on_short_password(): void
    {
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        $response = $this->actingAs($this->adminUser)->post(route('users.store'), [
            'name' => 'Short Pass User',
            'email' => 'shortpass@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'role' => 'staff',
            'password' => '12345',
        ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_can_update_user_details(): void
    {
        Role::firstOrCreate(['name' => 'counselor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'counselor_lead', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Phạm Hoàng Long',
            'email' => 'long.pham@menglish.edu.vn',
        ]);
        $user->assignRole('counselor');

        $response = $this->actingAs($this->adminUser)->put(route('users.update', $user), [
            'name' => 'Phạm Hoàng Long (Lead)',
            'email' => 'long.pham@menglish.edu.vn',
            'employee_code' => 'NV-0088',
            'phone' => '0912345678',
            'branch_id' => $this->branch->id,
            'role' => 'counselor_lead',
        ]);

        $response->assertRedirect(route('users.index'));
        $user->refresh();
        $this->assertEquals('Phạm Hoàng Long (Lead)', $user->name);
        $this->assertEquals('NV-0088', $user->employee_code);
        $this->assertTrue($user->hasRole('counselor_lead'));
        $this->assertFalse($user->hasRole('counselor'));
    }

    public function test_can_lock_unlock_and_reset_password_for_user(): void
    {
        $user = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'locked_at' => null,
        ]);

        // Lock user
        $responseLock = $this->actingAs($this->adminUser)->post(route('users.lock', $user));
        $responseLock->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->locked_at);
        $this->assertTrue($user->isLocked());

        // Unlock user
        $responseUnlock = $this->actingAs($this->adminUser)->post(route('users.unlock', $user));
        $responseUnlock->assertRedirect();
        $user->refresh();
        $this->assertNull($user->locked_at);
        $this->assertFalse($user->isLocked());

        // Reset password
        $oldPasswordHash = $user->password;
        $responseReset = $this->actingAs($this->adminUser)->post(route('users.reset-password', $user));
        $responseReset->assertRedirect();
        $user->refresh();
        $this->assertNotEquals($oldPasswordHash, $user->password);
    }

    public function test_user_cannot_lock_or_delete_themselves(): void
    {
        $responseLock = $this->actingAs($this->adminUser)->post(route('users.lock', $this->adminUser));
        $responseLock->assertSessionHasErrors(['user']);

        $responseDelete = $this->actingAs($this->adminUser)->delete(route('users.destroy', $this->adminUser));
        $responseDelete->assertSessionHasErrors(['user']);
    }

    // =========================================================================
    // d. Assigning roles, checking user->roles, user->hasPermissionTo, and overrides
    // =========================================================================

    public function test_assign_roles_to_user_and_verify_permissions(): void
    {
        $role1 = Role::firstOrCreate(['name' => 'sales_staff', 'guard_name' => 'web']);
        $role2 = Role::firstOrCreate(['name' => 'support_staff', 'guard_name' => 'web']);

        $permLeadView = Permission::firstOrCreate(['name' => 'lead.view', 'guard_name' => 'web']);
        $permTicketView = Permission::firstOrCreate(['name' => 'ticket.view', 'guard_name' => 'web']);

        $role1->givePermissionTo($permLeadView);
        $role2->givePermissionTo($permTicketView);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->adminUser)->put(route('users.roles.update', $user), [
            'roles' => [$role1->name, $role2->name],
        ]);

        $response->assertRedirect(route('users.index'));
        $user->refresh();

        $this->assertTrue($user->hasRole('sales_staff'));
        $this->assertTrue($user->hasRole('support_staff'));
        $this->assertTrue($user->hasPermissionTo('lead.view'));
        $this->assertTrue($user->hasPermissionTo('ticket.view'));
    }

    public function test_permission_override_can_deny_role_granted_permission(): void
    {
        $role = Role::firstOrCreate(['name' => 'general_staff', 'guard_name' => 'web']);
        $perm = Permission::firstOrCreate(['name' => 'tuition.delete', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        $this->assertTrue($user->hasPermissionTo('tuition.delete'));
        $this->assertTrue($user->hasModuleAction('tuition', 'delete'));

        // Apply override: deny tuition.delete
        $response = $this->actingAs($this->adminUser)->put(route('users.permissions.update', $user), [
            'overrides' => [
                'tuition' => [
                    'delete' => 'deny',
                ],
            ],
        ]);

        $response->assertRedirect(route('users.index'));
        $user->refresh();

        // Standard Spatie role-permission is still present
        $this->assertTrue($user->hasPermissionTo('tuition.delete'));
        // But custom module action override evaluates to false
        $this->assertFalse($user->hasModuleAction('tuition', 'delete'));
    }

    public function test_permission_override_can_grant_unassigned_permission(): void
    {
        Permission::firstOrCreate(['name' => 'payroll.approve', 'guard_name' => 'web']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->assertFalse($user->hasPermissionTo('payroll.approve'));
        $this->assertFalse($user->hasModuleAction('payroll', 'approve'));

        // Apply override: allow payroll.approve
        $response = $this->actingAs($this->adminUser)->put(route('users.permissions.update', $user), [
            'overrides' => [
                'payroll' => [
                    'approve' => 'allow',
                ],
            ],
        ]);

        $response->assertRedirect(route('users.index'));
        $user->refresh();

        $this->assertTrue($user->hasModuleAction('payroll', 'approve'));
    }

    public function test_permission_override_inherit_removes_override(): void
    {
        $role = Role::firstOrCreate(['name' => 'hr_staff', 'guard_name' => 'web']);
        $perm = Permission::firstOrCreate(['name' => 'penalty.create', 'guard_name' => 'web']);
        $role->givePermissionTo($perm);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        // First set to deny
        UserPermissionOverride::create([
            'user_id' => $user->id,
            'module' => 'penalty',
            'action' => 'create',
            'scope_type' => UserPermissionOverride::SCOPE_ALL,
            'allow' => false,
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertFalse($user->hasModuleAction('penalty', 'create'));

        // Set to inherit (removes override)
        $this->actingAs($this->adminUser)->put(route('users.permissions.update', $user), [
            'overrides' => [
                'penalty' => [
                    'create' => 'inherit',
                ],
            ],
        ]);

        $user->refresh();
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $user->id,
            'module' => 'penalty',
            'action' => 'create',
        ]);
        $this->assertTrue($user->hasModuleAction('penalty', 'create'));
    }
}

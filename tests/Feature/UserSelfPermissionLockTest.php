<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithInertia;
use Tests\TestCase;

/** TK-2026-0123: không ai (kể cả Admin) xem / sửa phân quyền cá nhân của chính mình. */
class UserSelfPermissionLockTest extends TestCase
{
    use InteractsWithInertia;
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SL', 'is_active' => true]);
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true, 'branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(): array
    {
        return ['overrides' => ['survey' => ['manage' => 'deny']]];
    }

    public function test_admin_cannot_update_own_permissions(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->put(route('users.permissions.update', $admin), $this->payload())->assertForbidden();

        $this->assertSame(0, UserPermissionOverride::where('user_id', $admin->id)->count());
        // The generic audit middleware may log the rejected 403 attempt; no override-change entry may exist.
        $this->assertSame(0, Activity::where('description', 'Cập nhật phân quyền chi tiết cá nhân')->count());
    }

    public function test_non_admin_with_override_permission_cannot_update_own_permissions(): void
    {
        $manager = $this->userWithRole('manager');
        $manager->givePermissionTo('permission.override');

        $this->actingAs($manager)->put(route('users.permissions.update', $manager), $this->payload())->assertForbidden();

        $this->assertSame(0, UserPermissionOverride::where('user_id', $manager->id)->count());
    }

    public function test_own_edit_page_is_forbidden_with_no_save_control(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get(route('users.permissions.edit', $admin))
            ->assertForbidden()
            ->assertDontSee('permissionOverrideForm', false)
            ->assertDontSee('Lưu thay đổi');
    }

    public function test_admin_can_still_update_other_users_permissions(): void
    {
        $admin = $this->userWithRole('admin');
        $teacher = $this->userWithRole('teacher');

        $this->actingAs($admin)->get(route('users.permissions.edit', $teacher))->assertOk();
        $this->actingAs($admin)->put(route('users.permissions.update', $teacher), ['overrides' => ['lead' => ['view' => 'allow']]])
            ->assertRedirect();

        $this->assertTrue($teacher->fresh()->can('lead.view'));
    }
}

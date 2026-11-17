<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\DataScope;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TK-2026-0126: "Đặt lại mặc định" + Lưu phải xóa cả override lẫn phạm vi dữ liệu cá nhân (data_scope) của mọi module. */
class UserPermissionResetDefaultTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $target;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $branch = Branch::create(['name' => 'CN Reset', 'code' => 'RS', 'is_active' => true]);
        $make = function (string $role) use ($branch) {
            $u = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
            $u->assignRole($role);

            return $u;
        };
        $this->admin = $make('admin');
        $this->target = $make('manager');
        $this->other = $make('manager');
    }

    /** Payload đúng như form sau khi bấm "Đặt lại mặc định": mọi data_scope = inherit, overrides = inherit, scope = all. */
    private function resetPayload(): array
    {
        return [
            'overrides' => ['class' => ['view' => 'inherit', 'edit' => 'inherit']],
            'scope' => ['class' => ['type' => 'all']],
            'data_scope' => collect(PermissionCatalog::scopedModules())->map(fn () => 'inherit')->all(),
        ];
    }

    public function test_reset_removes_overrides_and_personal_scopes_for_target_only(): void
    {
        $set = [
            'overrides' => ['class' => ['view' => 'allow']],
            'data_scope' => ['class' => 'own', 'kpi' => 'all'],
        ];
        foreach ([$this->target, $this->other] as $user) {
            $this->actingAs($this->admin)->put(route('users.permissions.update', $user), $set)->assertSessionHasNoErrors();
        }
        $this->assertSame('own', DataScope::level($this->target->fresh(), 'class'));
        $otherBefore = $this->other->permissionOverrides()->orderBy('id')->get(['module', 'action', 'allow', 'scope_type'])->toArray();
        $this->assertNotEmpty($otherBefore);

        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->target), $this->resetPayload())->assertSessionHasNoErrors();

        $this->assertSame(0, $this->target->permissionOverrides()->count());
        $roleDefault = DataScope::level($this->manager(), 'class');
        $this->assertSame($roleDefault, DataScope::level($this->target->fresh(), 'class'));
        // Guard: người khác không bị đụng tới.
        $this->assertSame($otherBefore, $this->other->permissionOverrides()->orderBy('id')->get(['module', 'action', 'allow', 'scope_type'])->toArray());
        $this->assertSame('own', DataScope::level($this->other->fresh(), 'class'));
    }

    public function test_reset_does_not_change_authorization_rules(): void
    {
        // Không tự phân quyền cho chính mình (TK-2026-0123) và cấp dưới vẫn áp dụng khi reset.
        $this->actingAs($this->admin)->put(route('users.permissions.update', $this->admin), $this->resetPayload())->assertForbidden();
        $this->actingAs($this->target)->put(route('users.permissions.update', $this->other), $this->resetPayload())->assertForbidden();
    }

    private function manager(): User
    {
        $fresh = User::factory()->create(['branch_id' => $this->target->branch_id, 'is_active' => true]);
        $fresh->assignRole('manager');

        return $fresh;
    }
}

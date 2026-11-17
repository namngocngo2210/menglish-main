<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemCategory;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TK-2026-0134: ô vai trò của form nhân sự lấy từ danh mục Chức vụ; phân quyền vẫn theo vai trò ánh xạ (role_code),
 * chức vụ chưa ánh xạ không cấp quyền nào.
 */
class UserPositionRoleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(SystemCategorySeeder::class);
        $this->branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG', 'is_active' => true]);
        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function position(string $code): SystemCategory
    {
        return SystemCategory::query()->ofType(SystemCategory::TYPE_POSITION)->where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(?SystemCategory $position, array $extra = []): array
    {
        return [
            'name' => 'Nhân sự mới',
            'email' => 'new'.uniqid().'@menglish.edu.vn',
            'branch_id' => $this->branch->id,
            'position_id' => $position?->id,
            'password' => 'Password123!',
            ...$extra,
        ];
    }

    public function test_guard_every_staff_role_has_a_seeded_position(): void
    {
        $mapped = SystemCategory::query()->ofType(SystemCategory::TYPE_POSITION)->pluck('role_code')->filter()->all();
        foreach (array_diff(Roles::ALL, [Roles::STUDENT]) as $role) {
            $this->assertContains($role, $mapped, "Chức vụ ánh xạ vai trò {$role} bị thiếu trong seed.");
        }
        $this->assertNotContains(Roles::STUDENT, $mapped);
    }

    public function test_form_options_come_from_active_positions_and_new_one_appears(): void
    {
        $this->position('POS_08')->update(['is_active' => false]);
        $new = SystemCategory::create(['type' => 'position', 'code' => 'POS_99', 'name' => 'Chuyên viên truyền thông', 'is_active' => true]);

        $props = $this->actingAs($this->admin)->get(route('users.create'))->assertOk()->viewData('page')['props'];
        $labels = array_column($props['positionOptions'], 'label');

        $this->assertContains('Chuyên viên truyền thông', $labels);
        $this->assertNotContains('Trợ giảng', $labels, 'Chức vụ ngừng dùng không được hiện.');
        $this->assertNull($props['positionRoles'][$new->id]);
    }

    public function test_mapped_position_assigns_its_role(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->payload($this->position('POS_06'), ['email' => 'gv@menglish.edu.vn']))
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'gv@menglish.edu.vn')->firstOrFail();
        $this->assertSame([Roles::TEACHER_FULLTIME], $user->getRoleNames()->all());
        $this->assertSame($this->position('POS_06')->id, $user->position_id);
    }

    public function test_client_cannot_override_role_derived_from_position(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->payload($this->position('POS_08'), ['email' => 'tamper@menglish.edu.vn', 'role' => Roles::ADMIN]))
            ->assertRedirect(route('users.index'));

        $this->assertSame([Roles::ASSISTANT], User::where('email', 'tamper@menglish.edu.vn')->firstOrFail()->getRoleNames()->all());
    }

    public function test_unmapped_position_grants_no_role_or_permission(): void
    {
        $position = SystemCategory::create(['type' => 'position', 'code' => 'POS_99', 'name' => 'Chuyên viên truyền thông', 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('users.store'), $this->payload($position, ['email' => 'free@menglish.edu.vn', 'role' => Roles::MANAGER, 'concurrent_roles_present' => 1, 'concurrent_roles' => [Roles::MANAGER]]))
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'free@menglish.edu.vn')->firstOrFail();
        $this->assertSame($position->id, $user->position_id);
        $this->assertCount(0, $user->getRoleNames());
        $this->assertCount(0, $user->getAllPermissions());
    }

    public function test_non_admin_cannot_assign_admin_via_position(): void
    {
        $manager = $this->makeUser(Roles::ACADEMIC_STAFF);

        $this->actingAs($manager)->post(route('users.store'), $this->payload($this->position('POS_01'), ['email' => 'esc@menglish.edu.vn']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'esc@menglish.edu.vn']);
    }

    public function test_non_admin_cannot_use_unmapped_position(): void
    {
        $position = SystemCategory::create(['type' => 'position', 'code' => 'POS_99', 'name' => 'Tự do', 'is_active' => true]);
        $manager = $this->makeUser(Roles::ACADEMIC_STAFF);

        $this->actingAs($manager)->post(route('users.store'), $this->payload($position, ['email' => 'orphan@menglish.edu.vn']))
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'orphan@menglish.edu.vn']);

        $props = $this->actingAs($manager)->get(route('users.create'))->viewData('page')['props'];
        $this->assertArrayNotHasKey($position->id, $props['positionRoles']);
        $this->assertArrayNotHasKey($this->position('POS_01')->id, $props['positionRoles'], 'Học vụ không thấy chức vụ Admin.');
    }

    public function test_inactive_position_is_rejected(): void
    {
        $position = $this->position('POS_08');
        $position->update(['is_active' => false]);

        $this->actingAs($this->admin)->post(route('users.store'), $this->payload($position, ['email' => 'off@menglish.edu.vn']))
            ->assertSessionHasErrors('position_id');
        $this->assertDatabaseMissing('users', ['email' => 'off@menglish.edu.vn']);
    }

    public function test_legacy_role_only_request_and_edit_form_keep_working(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->payload(null, ['email' => 'legacy@menglish.edu.vn', 'role' => Roles::ACADEMIC_STAFF]))
            ->assertRedirect(route('users.index'));
        $user = User::where('email', 'legacy@menglish.edu.vn')->firstOrFail();
        $this->assertSame([Roles::ACADEMIC_STAFF], $user->getRoleNames()->all());

        $props = $this->actingAs($this->admin)->get(route('users.edit', $user))->assertOk()->viewData('page')['props'];
        $this->assertSame($this->position('POS_04')->id, $props['currentPositionId'], 'Người chưa có chức vụ được chọn sẵn chức vụ cùng vai trò.');

        $this->actingAs($this->admin)->put(route('users.update', $user), $this->payload($this->position('POS_03'), ['email' => 'legacy@menglish.edu.vn', 'password' => null]))
            ->assertRedirect();
        $this->assertSame([Roles::SALES_CONSULTANT], $user->fresh()->getRoleNames()->all());
    }

    public function test_category_role_code_must_be_a_staff_role(): void
    {
        $base = ['type' => 'position', 'code' => 'POS_77', 'name' => 'Thử'];

        $this->actingAs($this->admin)->post(route('system-categories.store'), $base + ['role_code' => 'hacker'])->assertSessionHasErrors('role_code');
        $this->actingAs($this->admin)->post(route('system-categories.store'), $base + ['role_code' => Roles::STUDENT])->assertSessionHasErrors('role_code');
        $this->actingAs($this->admin)->post(route('system-categories.store'), $base + ['role_code' => Roles::ACCOUNTANT]);
        $this->assertDatabaseHas('system_categories', ['code' => 'POS_77', 'role_code' => Roles::ACCOUNTANT]);
    }
}

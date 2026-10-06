<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\DataScope;
use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cây vai trò "chỉ thấy cấp dưới" (chủ dự án 06/10/2026) trên màn Người dùng: Admin thấy tất cả; Quản lý cơ sở thấy nhân sự +
 * học viên chi nhánh mình trừ Admin và Quản lý cơ sở; Học vụ thấy học viên, giáo viên, trợ giảng, Học thuật chi nhánh mình;
 * Học thuật thấy giáo viên mọi chi nhánh. Không ai thấy chính mình hay người ngang cấp; backend chặn thao tác ngoài cây.
 */
class UserSubordinatesOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Đội Cấn', 'code' => 'DC-SUB', 'is_active' => true]);
        $this->otherBranch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG-SUB', 'is_active' => true]);

        foreach (Roles::ALL as $role) {
            $this->people[$role] = $this->person($role, $this->branch);
        }
        $this->people['other_manager'] = $this->person(Roles::MANAGER, $this->branch);
        $this->people['other_staff'] = $this->person(Roles::ACADEMIC_STAFF, $this->branch);
        $this->people['far_teacher'] = $this->person(Roles::TEACHER_FULLTIME, $this->otherBranch);
        $this->people['far_assistant'] = $this->person(Roles::ASSISTANT, $this->otherBranch);
        // Học thuật kiêm giáo viên: Học thuật không quản lý được (có vai trò ngoài cây của mình).
        $this->people['lead_teaching'] = $this->person(Roles::ACADEMIC_LEAD, $this->branch, Roles::TEACHER_FULLTIME);
    }

    private function person(string $role, Branch $branch, ?string $extraRole = null): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole(array_filter([$role, $extraRole]));

        return $user;
    }

    /** @return list<string> khóa trong $people hiện trong danh sách Người dùng của $viewer */
    private function visibleKeys(User $viewer, array $query = []): array
    {
        $page = $this->actingAs($viewer)->get(route('users.index', $query + ['per_page' => 100]))->assertOk()->viewData('page');
        $ids = collect($page['props']['users']['data'])->pluck('id')->all();

        return collect($this->people)->filter(fn (User $u) => in_array($u->id, $ids, true))->keys()->sort()->values()->all();
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    public function test_admin_sees_everyone(): void
    {
        $this->assertSame($this->sorted(array_keys($this->people)), $this->visibleKeys($this->people[Roles::ADMIN]));
    }

    public function test_branch_manager_sees_branch_staff_and_students_except_admin_and_managers(): void
    {
        $manager = $this->people[Roles::MANAGER];

        $this->assertSame($this->sorted([
            Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF, Roles::SALES_CONSULTANT, Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME,
            Roles::ASSISTANT, Roles::STUDENT, 'other_staff', 'lead_teaching',
        ]), $this->visibleKeys($manager));

        $page = $this->actingAs($manager)->get(route('users.index'))->viewData('page');
        $roleFilter = collect($page['props']['roles'])->pluck('value')->all();
        $this->assertNotContains(Roles::ADMIN, $roleFilter);
        $this->assertNotContains(Roles::MANAGER, $roleFilter);
        $this->assertSame(9, $page['props']['stats']['total']);

        // Lọc theo vai trò ngoài cây cũng không lộ ra ai.
        $this->assertSame([], $this->visibleKeys($manager, ['role' => Roles::MANAGER]));

        $this->actingAs($manager)->post(route('users.lock', $this->people['other_manager']))->assertForbidden();
        $this->actingAs($manager)->get(route('users.show', $this->people['other_manager']))->assertForbidden();
        $this->actingAs($manager)->post(route('users.lock', $this->people[Roles::ACADEMIC_STAFF]))->assertRedirect();
        $this->assertNotNull($this->people[Roles::ACADEMIC_STAFF]->fresh()->locked_at);
    }

    public function test_academic_staff_sees_students_teachers_assistants_and_academic_lead_of_branch(): void
    {
        $staff = $this->people[Roles::ACADEMIC_STAFF];

        $this->assertSame($this->sorted([
            Roles::ACADEMIC_LEAD, Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, Roles::ASSISTANT, Roles::STUDENT, 'lead_teaching',
        ]), $this->visibleKeys($staff));

        // Sửa Học thuật được (CRU); Học vụ khác, Quản lý cơ sở, Tư vấn, giáo viên chi nhánh khác thì không.
        $lead = $this->people[Roles::ACADEMIC_LEAD];
        $this->actingAs($staff)->put(route('users.update', $lead), [
            'name' => 'Học Thuật Đổi Tên', 'email' => $lead->email, 'branch_id' => $this->branch->id, 'role' => Roles::ACADEMIC_LEAD,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Học Thuật Đổi Tên', $lead->fresh()->name);

        foreach (['other_staff', Roles::MANAGER, Roles::SALES_CONSULTANT, 'far_teacher'] as $key) {
            $this->actingAs($staff)->get(route('users.edit', $this->people[$key]))->assertForbidden();
        }
    }

    public function test_academic_lead_sees_only_teachers_of_every_branch(): void
    {
        $lead = $this->people[Roles::ACADEMIC_LEAD];

        $this->assertSame($this->sorted([Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, 'far_teacher']), $this->visibleKeys($lead));

        $this->actingAs($lead)->get(route('users.edit', $this->people['far_teacher']))->assertOk();
        foreach ([Roles::ASSISTANT, Roles::STUDENT, Roles::ACADEMIC_STAFF, 'lead_teaching'] as $key) {
            $this->actingAs($lead)->get(route('users.edit', $this->people[$key]))->assertForbidden();
        }
    }

    public function test_roles_without_user_view_see_nothing(): void
    {
        foreach ([Roles::SALES_CONSULTANT, Roles::TEACHER_FULLTIME, Roles::ASSISTANT, Roles::STUDENT] as $role) {
            $this->actingAs($this->people[$role])->get(route('users.index'))->assertForbidden();
        }
    }

    public function test_personal_permissions_only_for_subordinates(): void
    {
        $staff = $this->people[Roles::ACADEMIC_STAFF];
        $staff->givePermissionTo('permission.override');

        $this->actingAs($staff)->get(route('users.permissions.edit', $this->people[Roles::MANAGER]))->assertForbidden();
        $this->actingAs($staff)->get(route('users.permissions.edit', $this->people[Roles::TEACHER_FULLTIME]))->assertOk();
    }

    public function test_migration_moves_running_system_to_subordinate_tree(): void
    {
        // Cấu hình cũ trên hệ thống đang chạy.
        Role::findByName(Roles::MANAGER, 'web')->givePermissionTo(Rbac::assignRolePermission(Roles::MANAGER));
        $staffRole = Role::findByName(Roles::ACADEMIC_STAFF, 'web');
        $staffRole->revokePermissionTo([Rbac::assignRolePermission(Roles::ACADEMIC_LEAD), 'user.scope_branch']);
        $staffRole->givePermissionTo('user.scope_own');
        $leadRole = Role::findByName(Roles::ACADEMIC_LEAD, 'web');
        $leadRole->revokePermissionTo('user.scope_all');
        $leadRole->givePermissionTo('user.scope_own');
        Rbac::flushCache();

        (require database_path('migrations/2026_11_01_090000_user_management_subordinates_only.php'))->up();

        $this->assertFalse(Rbac::canAssignRole($this->people[Roles::MANAGER]->fresh(), Roles::MANAGER));
        $this->assertTrue(Rbac::canAssignRole($this->people[Roles::ACADEMIC_STAFF]->fresh(), Roles::ACADEMIC_LEAD));
        $this->assertSame('branch', DataScope::level($this->people[Roles::ACADEMIC_STAFF]->fresh(), 'user'));
        $this->assertSame('all', DataScope::level($this->people[Roles::ACADEMIC_LEAD]->fresh(), 'user'));
    }
}

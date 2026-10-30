<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Rbac;
use App\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignTeacherRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'foreigner_teacher';

    public function test_empty_database_is_left_for_seeders(): void
    {
        Role::query()->get()->each->delete();
        $permissionCount = Permission::count();

        $this->migrateRole();

        $this->assertSame(0, Role::count());
        $this->assertSame($permissionCount, Permission::count());
    }

    public function test_legacy_role_is_renamed_with_users_permissions_and_overrides(): void
    {
        $this->removeTarget();
        $legacy = Role::create(['name' => self::LEGACY, 'guard_name' => 'web', 'label' => 'GVNN tùy chỉnh']);
        $legacy->givePermissionTo('class.view');
        $user = User::factory()->create();
        $user->assignRole($legacy);
        $user->delete();
        $assign = Permission::findOrCreate(Rbac::assignRolePermission(self::LEGACY), 'web');
        Role::findByName(Roles::ACADEMIC_STAFF)->givePermissionTo($assign);
        $override = $this->override(User::factory()->create(), false);

        $this->migrateRole();
        $this->migrateRole();

        $target = Role::findByName(Roles::FOREIGN_TEACHER);
        $this->assertSame($legacy->id, $target->id);
        $this->assertSame('GVNN tùy chỉnh', $target->label);
        $this->assertSame(['class.view'], $target->permissions->pluck('name')->all());
        $this->assertTrue(User::withTrashed()->findOrFail($user->id)->hasRole(Roles::FOREIGN_TEACHER));
        $this->assertSame($assign->id, Permission::findByName(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER))->id);
        $this->assertSame('assign_role.'.Roles::FOREIGN_TEACHER, $override->fresh()->action);
        $this->assertFalse($override->fresh()->allow);
        $this->assertLegacyRemoved();
    }

    public function test_empty_legacy_role_keeps_its_user_and_receives_default_permissions(): void
    {
        $this->removeTarget();
        $legacy = Role::create(['name' => self::LEGACY, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($legacy);
        Permission::findOrCreate(Rbac::assignRolePermission(self::LEGACY), 'web');

        $this->migrateRole();
        $this->migrateRole();

        $target = Role::findByName(Roles::FOREIGN_TEACHER);
        $this->assertSame($legacy->id, $target->id);
        $this->assertTrue($user->fresh()->hasRole(Roles::FOREIGN_TEACHER));
        $this->assertDefaults($target);
        $this->assertLegacyRemoved();
    }

    public function test_renaming_merges_a_preexisting_assignment_permission_without_losing_grants(): void
    {
        Role::findByName(Roles::FOREIGN_TEACHER)->delete();
        $existing = Permission::findByName(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER));
        $legacy = Role::create(['name' => self::LEGACY, 'guard_name' => 'web']);
        $old = Permission::findOrCreate(Rbac::assignRolePermission(self::LEGACY), 'web');
        $custom = Role::create(['name' => 'quan_ly_gv_cu', 'guard_name' => 'web']);
        $custom->givePermissionTo($existing);
        $actor = User::factory()->create();
        $actor->givePermissionTo($existing);

        $this->migrateRole();
        $this->migrateRole();

        $this->assertSame($legacy->id, Role::findByName(Roles::FOREIGN_TEACHER)->id);
        $this->assertSame($old->id, Permission::findByName(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER))->id);
        $this->assertTrue($custom->fresh()->hasPermissionTo(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
        $this->assertTrue($actor->fresh()->hasDirectPermission(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
        $this->assertLegacyRemoved();
    }

    public function test_legacy_allow_does_not_replace_existing_global_target_deny(): void
    {
        $this->assertExistingTargetDenyWins(UserPermissionOverride::SCOPE_ALL, null);
    }

    public function test_legacy_allow_does_not_collide_with_existing_scoped_target_deny(): void
    {
        $this->assertExistingTargetDenyWins(UserPermissionOverride::SCOPE_BRANCH, 42);
    }

    public function test_both_roles_move_users_and_keep_target_custom_permissions(): void
    {
        $target = Role::findByName(Roles::FOREIGN_TEACHER);
        $target->syncPermissions(['class.view']);
        $legacy = Role::create(['name' => self::LEGACY, 'guard_name' => 'web']);
        $legacy->givePermissionTo('class.update');
        Permission::findOrCreate(Rbac::assignRolePermission(self::LEGACY), 'web');
        $user = User::factory()->create();
        $user->assignRole([$target, $legacy]);
        $deleted = User::factory()->create();
        $deleted->assignRole($legacy);
        $deleted->delete();
        $override = $this->override(User::factory()->create(), true);

        $this->migrateRole();
        $this->migrateRole();

        $this->assertSame($target->id, Role::findByName(Roles::FOREIGN_TEACHER)->id);
        $this->assertSame(['class.view'], $target->fresh()->permissions->pluck('name')->all());
        $this->assertSame([Roles::FOREIGN_TEACHER], $user->fresh()->getRoleNames()->all());
        $this->assertTrue(User::withTrashed()->findOrFail($deleted->id)->hasRole(Roles::FOREIGN_TEACHER));
        $this->assertNull($override->fresh());
        $this->assertLegacyRemoved();
    }

    public function test_missing_target_is_created_with_defaults_and_assignment_hierarchy(): void
    {
        $this->removeTarget();
        $custom = Role::create(['name' => 'quan_ly_gv', 'guard_name' => 'web']);
        $custom->givePermissionTo(Rbac::assignRolePermission(Roles::TEACHER_PARTTIME));

        $this->migrateRole();
        $this->migrateRole();

        $target = Role::findByName(Roles::FOREIGN_TEACHER);
        $this->assertDefaults($target);
        $this->assertSame(Roles::LABELS[Roles::FOREIGN_TEACHER], $target->label);
        foreach ([Roles::MANAGER, Roles::ACADEMIC_LEAD, Roles::ACADEMIC_STAFF, 'quan_ly_gv'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
        }
        $this->assertFalse(Role::findByName(Roles::SALES_CONSULTANT)->hasPermissionTo(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
    }

    public function test_empty_target_gets_defaults_but_existing_custom_permissions_are_not_overwritten(): void
    {
        $target = Role::findByName(Roles::FOREIGN_TEACHER);
        $target->syncPermissions([]);
        $target->forceFill(['label' => null])->save();

        $this->migrateRole();
        $this->assertDefaults($target->fresh());
        $this->assertSame(Roles::LABELS[Roles::FOREIGN_TEACHER], $target->fresh()->label);

        $target->syncPermissions(['notification.view']);
        $this->migrateRole();
        $this->assertSame(['notification.view'], $target->fresh()->permissions->pluck('name')->all());
        $this->runSeederForReal(PermissionSeeder::class)->runSeederForReal(RoleSeeder::class);
        $this->assertSame(['notification.view'], $target->fresh()->permissions->pluck('name')->all());
    }

    public function test_down_keeps_the_unified_role_and_assignments(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::FOREIGN_TEACHER);
        $migration = require database_path('migrations/2026_12_04_090000_unify_foreign_teacher_role.php');
        $migration->down();

        $this->assertTrue($user->fresh()->hasRole(Roles::FOREIGN_TEACHER));
        $this->assertLegacyRemoved();
    }

    private function assertExistingTargetDenyWins(string $scopeType, ?int $scopeId): void
    {
        $this->removeTarget();
        Role::create(['name' => self::LEGACY, 'guard_name' => 'web']);
        $actor = User::factory()->create();
        $actor->assignRole(Roles::ACADEMIC_STAFF);
        $source = UserPermissionOverride::create([
            'user_id' => $actor->id, 'module' => 'user', 'action' => 'assign_role.'.self::LEGACY,
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'allow' => true,
        ]);
        $target = UserPermissionOverride::create([
            'user_id' => $actor->id, 'module' => 'user', 'action' => 'assign_role.'.Roles::FOREIGN_TEACHER,
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'allow' => false,
        ]);
        $otherScope = UserPermissionOverride::create([
            'user_id' => $actor->id, 'module' => 'user', 'action' => 'assign_role.'.self::LEGACY,
            'scope_type' => UserPermissionOverride::SCOPE_BRANCH, 'scope_id' => 43, 'allow' => true,
        ]);

        $this->migrateRole();
        $this->migrateRole();

        $this->assertNull($source->fresh());
        $this->assertFalse($target->fresh()->allow);
        $matches = UserPermissionOverride::query()->where('user_id', $actor->id)->where('module', 'user')
            ->where('action', 'assign_role.'.Roles::FOREIGN_TEACHER)->where('scope_type', $scopeType)->where('scope_id', $scopeId)->get();
        $this->assertSame([$target->id], $matches->modelKeys());
        $this->assertSame('assign_role.'.Roles::FOREIGN_TEACHER, $otherScope->fresh()->action);
        $this->assertTrue($otherScope->fresh()->allow);
        $this->assertTrue(Role::findByName(Roles::ACADEMIC_STAFF)->hasPermissionTo(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
        if ($scopeType === UserPermissionOverride::SCOPE_ALL) {
            $this->assertFalse($actor->fresh()->can(Rbac::assignRolePermission(Roles::FOREIGN_TEACHER)));
        } else {
            $this->assertFalse($actor->fresh()->hasModuleAction('user', 'assign_role.'.Roles::FOREIGN_TEACHER, $scopeType, $scopeId));
        }
        $this->assertLegacyRemoved();
    }

    private function removeTarget(): void
    {
        Role::findByName(Roles::FOREIGN_TEACHER)->delete();
        Rbac::forgetRole(Roles::FOREIGN_TEACHER);
    }

    private function migrateRole(): void
    {
        $migration = require database_path('migrations/2026_12_04_090000_unify_foreign_teacher_role.php');
        $migration->up();
    }

    private function override(User $user, bool $allow): UserPermissionOverride
    {
        return UserPermissionOverride::create([
            'user_id' => $user->id, 'module' => 'user', 'action' => 'assign_role.'.self::LEGACY,
            'scope_type' => UserPermissionOverride::SCOPE_ALL, 'allow' => $allow,
        ]);
    }

    private function assertDefaults(Role $role): void
    {
        $expected = RoleSeeder::resolvePatterns(config('access.roles.'.Roles::FOREIGN_TEACHER),
            Permission::query()->where('guard_name', 'web')->pluck('name'), Roles::FOREIGN_TEACHER);
        $this->assertEqualsCanonicalizing($expected->all(), $role->permissions->pluck('name')->all());
    }

    private function assertLegacyRemoved(): void
    {
        $this->assertDatabaseMissing('roles', ['name' => self::LEGACY, 'guard_name' => 'web']);
        $this->assertDatabaseMissing('permissions', ['name' => Rbac::assignRolePermission(self::LEGACY), 'guard_name' => 'web']);
        $this->assertDatabaseMissing('user_permission_overrides', ['module' => 'user', 'action' => 'assign_role.'.self::LEGACY]);
    }
}

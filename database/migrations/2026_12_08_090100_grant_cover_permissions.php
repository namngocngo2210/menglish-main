<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLES_BY_PERMISSION = [
        'cover.request' => [Roles::TEACHER_FULLTIME, Roles::TEACHER_PARTTIME, Roles::FOREIGN_TEACHER],
        'cover.approve' => [Roles::ACADEMIC_STAFF],
    ];

    public function up(): void
    {
        foreach (self::ROLES_BY_PERMISSION as $name => $roleNames) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach ($roleNames as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES_BY_PERMISSION as $name => $roleNames) {
            foreach ($roleNames as $roleName) {
                Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo($name);
            }
            Permission::query()->where('name', $name)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

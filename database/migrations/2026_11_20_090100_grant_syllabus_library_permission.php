<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Kho syllabus (docs/kho-syllabus.md §3.4): quyền syllabus.library (xem & sửa syllabus gốc) cho Quản lý và Học thuật;
 * Admin qua Gate::before. Học vụ giữ syllabus.manage (giao chặng, Big Test) nhưng không sửa syllabus gốc.
 */
return new class extends Migration
{
    private const ROLES = [Roles::MANAGER, Roles::ACADEMIC_LEAD];

    public function up(): void
    {
        $permission = Permission::findOrCreate('syllabus.library', 'web');
        foreach (self::ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo('syllabus.library');
        }
        Permission::query()->where('name', 'syllabus.library')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Kho syllabus chỉ đọc (docs/kho-syllabus.md §3.4): quyền syllabus.library_view (xem syllabus gốc + mọi tài liệu) cho
 * Học vụ (order in ấn) và cho người đang có quyền sửa Kho (Quản lý, Học thuật); Admin qua Gate::before.
 */
return new class extends Migration
{
    private const ROLES = [Roles::ACADEMIC_STAFF, Roles::MANAGER, Roles::ACADEMIC_LEAD];

    public function up(): void
    {
        $permission = Permission::findOrCreate('syllabus.library_view', 'web');
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
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo('syllabus.library_view');
        }
        Permission::query()->where('name', 'syllabus.library_view')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

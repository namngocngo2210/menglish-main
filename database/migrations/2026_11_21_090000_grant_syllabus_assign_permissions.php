<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quy trình syllabus giai đoạn 2 (docs/kho-syllabus.md §7.2): giao chặng (syllabus.assign) và sửa syllabus riêng của lớp
 * (syllabus.class_edit) chỉ cho Học thuật; Admin qua Gate::before. Học vụ / Quản lý không được cấp.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['syllabus.assign', 'syllabus.class_edit'];

    public function up(): void
    {
        $role = Role::query()->where('name', Roles::ACADEMIC_LEAD)->where('guard_name', 'web')->first();
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', Roles::ACADEMIC_LEAD)->where('guard_name', 'web')->first();
        foreach (self::PERMISSIONS as $name) {
            $role?->revokePermissionTo($name);
            Permission::query()->where('name', $name)->where('guard_name', 'web')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

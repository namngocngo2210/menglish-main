<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Giao việc 2 chiều (Phase 4): GV/TA được "đề xuất" việc cho Admin / Quản lý /
 * Học vụ / Học thuật qua quyền work_task.request (không phải work_task.create).
 */
return new class extends Migration
{
    private const ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    public function up(): void
    {
        $permission = Permission::findOrCreate('work_task.request', 'web');

        foreach (self::ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin && ! $admin->hasPermissionTo($permission)) {
            $admin->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'work_task.request')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

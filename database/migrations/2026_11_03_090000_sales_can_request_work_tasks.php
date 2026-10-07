<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Yêu cầu 07/10/2026: mọi nhân sự đều giao được việc cho Admin. Sales (chưa có quyền giao / đề xuất việc) được cấp
 * work_task.request như GV / TA — đề xuất việc cho Admin và người duyệt công việc.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('work_task.request', 'web');
        $role = Role::query()->where('name', Roles::SALES_CONSULTANT)->where('guard_name', 'web')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', Roles::SALES_CONSULTANT)->where('guard_name', 'web')->first()?->revokePermissionTo('work_task.request');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

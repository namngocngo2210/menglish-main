<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Báo cáo tuyển sinh (doanh số, hoa hồng từng Sales) chỉ Admin xem (quyết định 29/09/2026). Route crm.reports nay
 * cần report.view; thu hồi quyền này khỏi mọi vai trò khác (Quản lý cơ sở, Kế toán, Sales...). Admin vẫn có thể cấp
 * lại cho từng người trên màn Phân quyền.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->where('name', 'report.view')->where('guard_name', 'web')->first();
        if ($permission) {
            Role::query()->where('guard_name', 'web')->where('name', '!=', 'admin')->get()
                ->each(fn (Role $role) => $role->hasPermissionTo($permission) ? $role->revokePermissionTo($permission) : null);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::findOrCreate('report.view', 'web');
        foreach (['manager', 'accountant', 'sales_consultant'] as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

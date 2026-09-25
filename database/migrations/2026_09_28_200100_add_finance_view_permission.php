<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 4: báo cáo thu chi (finance.*) dùng quyền riêng finance.view thay cho report.view,
     * để Sale (có report.view cho báo cáo CRM) không mở được báo cáo thu chi toàn công ty.
     * Gán sẵn cho Admin / Kế toán / Quản lý cơ sở trên DB đang chạy (RoleSeeder cũng đã cập nhật).
     */
    public function up(): void
    {
        $permission = Permission::findOrCreate('finance.view', 'web');

        foreach (['admin', 'accountant', 'manager'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'finance.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

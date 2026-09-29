<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Chủ dự án 29/09/2026: người phụ trách khách CRM là Học vụ (cùng Admin). Cấp "Được nhận phụ trách khách" cho Học vụ,
 * bỏ khỏi Sale và Quản lý cơ sở (khách đang do họ phụ trách giữ nguyên tới khi đổi). Admin chỉnh lại được trên màn Vai trò.
 * Thêm quyền "Duyệt chuyển cơ sở" (Admin có sẵn qua Super Admin).
 */
return new class extends Migration
{
    private const GRANT = ['academic_staff'];

    private const REVOKE = ['sales_consultant', 'manager'];

    public function up(): void
    {
        $permission = Permission::findOrCreate('lead.be_assigned', 'web');
        Permission::findOrCreate('lead.approve_transfer', 'web');

        foreach (self::GRANT as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        foreach (self::REVOKE as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && $role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::findOrCreate('lead.be_assigned', 'web');
        foreach (self::GRANT as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo($permission);
        }
        foreach (self::REVOKE as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

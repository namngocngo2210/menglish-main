<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền module Chấm công hằng ngày (config/permission_catalog.php → staff_checkin). Mọi nhân sự (portal.staff) tự
 * chấm công và gửi đơn của mình, không cần quyền riêng. Admin có mọi quyền qua Super Admin.
 * Quản lý cơ sở: xem chấm công + duyệt đơn của chi nhánh mình. Kế toán: xem chấm công chi nhánh mình (đối soát lương).
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['staff_checkin.view', 'staff_checkin.approve', 'staff_checkin.scope_branch'],
        'accountant' => ['staff_checkin.view', 'staff_checkin.scope_branch'],
    ];

    private const PERMISSIONS = ['staff_checkin.view', 'staff_checkin.approve', 'staff_checkin.scope_branch', 'staff_checkin.scope_all'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            foreach ($permissions as $name) {
                if ($role && ! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

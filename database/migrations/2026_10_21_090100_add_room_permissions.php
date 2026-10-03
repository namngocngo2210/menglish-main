<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền module Phòng học (config/permission_catalog.php → room). Admin có mọi quyền qua Super Admin.
 * Quản lý cơ sở + Học vụ: xem / thêm / sửa phòng chi nhánh mình. Học thuật: xem, tra cứu phòng trống mọi chi nhánh.
 * Xóa phòng và danh mục loại phòng: chỉ Admin (mockup "Quản lý phòng học").
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['room.view', 'room.manage', 'room.scope_branch'],
        'academic_staff' => ['room.view', 'room.manage', 'room.scope_branch'],
        'academic_lead' => ['room.view', 'room.scope_all'],
    ];

    private const PERMISSIONS = ['room.view', 'room.manage', 'room.delete', 'room.manage_types', 'room.scope_branch', 'room.scope_all'];

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

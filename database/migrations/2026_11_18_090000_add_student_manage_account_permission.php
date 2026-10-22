<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền "Quản lý tài khoản học viên" (student.manage_account, docs/tai-khoan-hoc-vien.md): tạo tài khoản cổng học viên /
 * cấp lại mật khẩu tạm theo lô. Cấp cho vai trò đã có (chỉ thêm, không đụng cấu hình Admin đã sửa); Admin có qua Super Admin.
 */
return new class extends Migration
{
    private const PERMISSION = 'student.manage_account';

    private const ROLES = ['manager', 'academic_staff', 'academic_lead'];

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');

        foreach (self::ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo(self::PERMISSION)) {
                $role->givePermissionTo(self::PERMISSION);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

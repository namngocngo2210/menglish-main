<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Giáo viên, Trợ giảng, Kế toán thấy số thông báo trên chuông nhưng không mở được trang Thông báo (thiếu notification.view).
 * Cấp notification.view; trang chỉ hiện thông báo gửi cho chính người xem (AdminNotification::forRecipient).
 */
return new class extends Migration
{
    private const ROLES = ['accountant', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'];

    public function up(): void
    {
        $permission = Permission::findOrCreate('notification.view', 'web');
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
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo('notification.view');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

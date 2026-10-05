<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cấu hình hòm thư gửi (SMTP) tách khỏi quyền quản lý ticket (support_ticket.update): ai đổi được máy chủ SMTP thì đọc được
 * mọi email hệ thống gửi đi, kể cả email đặt lại mật khẩu của Admin. Quyền mới không gán cho vai trò nào — mặc định chỉ Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('mail_config.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'mail_config.manage')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

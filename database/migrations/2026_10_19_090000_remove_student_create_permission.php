<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Học viên chỉ sinh ra từ luồng Lead → Chốt (quyết định 30/09/2026): bỏ chức năng "Thêm học viên" không qua CRM
 * (route students.store) nên xóa luôn quyền student.create khỏi vai trò, người dùng và bảng ghi đè quyền.
 * Học viên đã tạo theo cách cũ giữ nguyên.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Xóa permission → Spatie xóa kèm role_has_permissions / model_has_permissions (khóa ngoại cascade).
        Permission::query()->where('name', 'student.create')->where('guard_name', 'web')->delete();
        DB::table('user_permission_overrides')->where('module', 'student')->where('action', 'create')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::findOrCreate('student.create', 'web');
        foreach (['manager', 'academic_staff'] as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

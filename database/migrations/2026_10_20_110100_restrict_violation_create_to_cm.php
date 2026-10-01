<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Chủ dự án chốt: người ghi nhận vi phạm là CM (Học vụ) hoặc Admin. Học thuật (academic_lead) không lập biên bản nữa
 * (vẫn xem và chốt lỗi chuyên môn). Quản lý cơ sở giữ quyền lập (cấp trên của CM tại chi nhánh).
 * Admin vẫn có thể cấp lại cho từng người trên màn Phân quyền.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->where('name', 'violation.create')->where('guard_name', 'web')->first();
        $role = Role::query()->where('name', 'academic_lead')->where('guard_name', 'web')->first();
        if ($permission && $role?->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::findOrCreate('violation.create', 'web');
        Role::query()->where('name', 'academic_lead')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

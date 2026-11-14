<?php

use App\Support\Rbac;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * TK-2026-0113: lập lại vai trò Kế toán (đã gộp vào Quản lý cơ sở ở 30/10) để duyệt phiếu thu / hủy hóa đơn / hoàn tiền,
 * tách khỏi Học vụ (người lập yêu cầu). Chỉ tạo khi chưa có và chỉ trên hệ thống đang chạy (đã có vai trò): cài mới do
 * RoleSeeder lo. Vai trò đã có thì không đụng (giữ cấu hình Admin đã chỉnh). Không chuyển ai sang vai trò này — Admin gán.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = fn () => Role::query()->where('name', Roles::ACCOUNTANT)->where('guard_name', 'web')->exists();
        if ($exists() || ! Role::query()->where('guard_name', 'web')->exists()) {
            return;
        }

        $all = Permission::query()->where('guard_name', 'web')->pluck('name');
        $role = Role::create(['name' => Roles::ACCOUNTANT, 'guard_name' => 'web']);
        $role->syncPermissions(RoleSeeder::resolvePatterns((array) config('access.roles.'.Roles::ACCOUNTANT, []), $all, Roles::ACCOUNTANT)->all());
        Rbac::registerRole($role);
        Rbac::flushCache();
    }

    public function down(): void
    {
        // Không hoàn tác: có thể đã có người giữ vai trò.
    }
};

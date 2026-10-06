<?php

use App\Support\Approvals\AdminOnlyApprovals;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Yêu cầu 06/10/2026: chỉ Admin duyệt / từ chối. Gate::before đã chặn các quyền duyệt (AdminOnlyApprovals::PERMISSIONS) với
 * mọi vai trò khác; migration này gỡ chúng khỏi vai trò, quyền gán trực tiếp và override "cho phép" để màn Vai trò / Phân
 * quyền cá nhân hiển thị đúng thực tế. Không hoàn tác được từng người (down() không cấp lại).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = Permission::query()->where('guard_name', 'web')->whereIn('name', AdminOnlyApprovals::PERMISSIONS)->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            $otherRoleIds = Role::query()->where('name', '!=', Roles::ADMIN)->pluck('id');
            DB::table('role_has_permissions')->whereIn('role_id', $otherRoleIds)->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        foreach (AdminOnlyApprovals::PERMISSIONS as $permission) {
            [$module, $action] = explode('.', $permission, 2);
            DB::table('user_permission_overrides')->where('module', $module)->where('action', $action)->where('allow', true)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Không cấp lại quyền duyệt cho vai trò khác Admin.
    }
};

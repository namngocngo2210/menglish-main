<?php

use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Cây vai trò "chỉ thấy cấp dưới" (chủ dự án 06/10/2026) trên màn Người dùng: ai cũng chỉ thấy / thao tác người có MỌI vai
 * trò nằm trong các vai trò mình được gán (user.assign_role.<vai trò>, App\Support\Rbac::scopeSubordinates).
 *  - Quản lý cơ sở: bỏ "gán vai trò Quản lý cơ sở" → không thấy / sửa Quản lý cơ sở khác (kể cả chính mình).
 *  - Học vụ: thêm "gán vai trò Học thuật" (Học vụ CRU khách, giáo viên, trợ giảng, Học thuật); phạm vi "Tài khoản do tôi
 *    tạo" → "Chi nhánh của tôi" để thấy cả giáo viên / học viên do Admin hoặc CRM tạo trong chi nhánh.
 *  - Học thuật: phạm vi "Tài khoản do tôi tạo" → "Mọi nhân sự" (giáo viên mọi chi nhánh).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Role::query()->where('guard_name', 'web')->exists()) {
            return; // Cài mới: RoleSeeder lo (config/access.php).
        }

        $role = fn (string $name) => Role::query()->where('guard_name', 'web')->where('name', $name)->first();
        $permission = fn (string $name) => Permission::findOrCreate($name, 'web');

        if ($manager = $role(Roles::MANAGER)) {
            $manager->revokePermissionTo($permission(Rbac::assignRolePermission(Roles::MANAGER)));
        }

        if ($academicStaff = $role(Roles::ACADEMIC_STAFF)) {
            $academicStaff->givePermissionTo($permission(Rbac::assignRolePermission(Roles::ACADEMIC_LEAD)));
            $academicStaff->revokePermissionTo($permission('user.scope_own'));
            $academicStaff->givePermissionTo($permission('user.scope_branch'));
        }

        if ($academicLead = $role(Roles::ACADEMIC_LEAD)) {
            $academicLead->revokePermissionTo($permission('user.scope_own'));
            $academicLead->givePermissionTo($permission('user.scope_all'));
        }

        Rbac::flushCache();
    }

    public function down(): void
    {
        $role = fn (string $name) => Role::query()->where('guard_name', 'web')->where('name', $name)->first();
        $permission = fn (string $name) => Permission::findOrCreate($name, 'web');

        $role(Roles::MANAGER)?->givePermissionTo($permission(Rbac::assignRolePermission(Roles::MANAGER)));
        if ($academicStaff = $role(Roles::ACADEMIC_STAFF)) {
            $academicStaff->revokePermissionTo($permission(Rbac::assignRolePermission(Roles::ACADEMIC_LEAD)));
            $academicStaff->revokePermissionTo($permission('user.scope_branch'));
            $academicStaff->givePermissionTo($permission('user.scope_own'));
        }
        if ($academicLead = $role(Roles::ACADEMIC_LEAD)) {
            $academicLead->revokePermissionTo($permission('user.scope_all'));
            $academicLead->givePermissionTo($permission('user.scope_own'));
        }

        Rbac::flushCache();
    }
};

<?php

use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Quản lý cơ sở chỉ thấy dữ liệu chi nhánh của mình (A6 Q7, chủ dự án): trước đây vai trò `manager` mặc định có
 * big_test.scope_all, kpi.scope_all, support_ticket.scope_all, activity_log.scope_all nên thấy Big Test, KPI, ticket và
 * nhật ký vận hành của mọi chi nhánh.
 *
 * Với vai trò `manager` đang chạy: module nào đang ở "Toàn hệ thống" → đổi sang "Chi nhánh" (thu hồi scope_all, cấp
 * scope_branch). Module Admin đã tự hạ xuống "Của tôi" thì giữ nguyên. Không đụng Phân quyền cá nhân / quyền trực tiếp.
 * Cài mới (chưa có vai trò nào): RoleSeeder cấp theo config/access.php.
 */
return new class extends Migration
{
    private const MODULES = ['big_test', 'kpi', 'support_ticket', 'activity_log'];

    public function up(): void
    {
        $this->move(Roles::MANAGER, 'all', 'branch');
    }

    public function down(): void
    {
        $this->move(Roles::MANAGER, 'branch', 'all');
    }

    /** Đổi mức phạm vi $from → $to của các module trên vai trò (chỉ module vai trò đang ở mức $from). */
    private function move(string $roleName, string $from, string $to): void
    {
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();
        if (! $role) {
            return; // Cài mới / vai trò đã bị xóa.
        }

        Rbac::flushCache();
        foreach (self::MODULES as $module) {
            $old = Permission::query()->where('guard_name', 'web')->where('name', "{$module}.scope_{$from}")->first();
            if (! $old || ! $role->hasPermissionTo($old)) {
                continue;
            }
            $role->revokePermissionTo($old);
            $role->givePermissionTo(Permission::findOrCreate("{$module}.scope_{$to}", 'web'));
        }

        Rbac::flushCache();
    }
};

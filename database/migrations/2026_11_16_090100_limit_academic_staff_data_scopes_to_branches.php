<?php

use App\Support\Rbac;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Học vụ (`academic_staff`) chỉ làm việc trên chi nhánh của mình: trước đây mặc định có class.scope_all,
 * big_test.scope_all, attendance_staff.scope_all, kpi.scope_all, work_task.scope_all nên thấy lớp, Big Test, chấm công,
 * KPI và công việc của mọi chi nhánh. Phương án mặc định khi chủ dự án chưa chốt — tách riêng để bỏ được độc lập
 * (xóa file này nếu chủ dự án muốn Học vụ thấy toàn hệ thống). Học thuật (`academic_lead`) giữ nguyên.
 *
 * Với vai trò `academic_staff` đang chạy: module nào đang ở "Toàn hệ thống" → "Chi nhánh"; module Admin đã tự hạ xuống
 * "Của tôi" thì giữ nguyên. Không đụng Phân quyền cá nhân / quyền trực tiếp. Cài mới: RoleSeeder theo config/access.php.
 */
return new class extends Migration
{
    private const MODULES = ['class', 'big_test', 'attendance_staff', 'kpi', 'work_task'];

    public function up(): void
    {
        $this->move(Roles::ACADEMIC_STAFF, 'all', 'branch');
    }

    public function down(): void
    {
        $this->move(Roles::ACADEMIC_STAFF, 'branch', 'all');
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

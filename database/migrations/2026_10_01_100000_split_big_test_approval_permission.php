<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Phase 2 — hoàn thiện: theo BPMN, Học thuật (academic_lead) duyệt order đề / kết quả Big Test,
     * đề xuất sửa giáo trình và giãn tiến độ. Học vụ (academic_staff) và Quản lý cơ sở (manager) trước
     * đây có `syllabus.*` nên cũng duyệt được.
     *
     * - Thêm quyền `big_test.approve` (duyệt & phân phối đề, duyệt kết quả, gửi kết quả PH) cho Admin + Học thuật.
     * - Thu hồi `syllabus.approve_adjustment` của Học vụ và Quản lý cơ sở (chỉ còn Admin + Học thuật).
     * Quyền cấp riêng cho từng người (model_has_permissions / override) không bị động tới.
     */
    public function up(): void
    {
        $bigTest = Permission::findOrCreate('big_test.approve', 'web');
        $approve = Permission::findOrCreate('syllabus.approve_adjustment', 'web');

        foreach (['admin', 'academic_lead'] as $roleName) {
            $role = $this->role($roleName);
            if ($role) {
                $role->givePermissionTo([$bigTest, $approve]);
            }
        }

        foreach (['academic_staff', 'manager'] as $roleName) {
            $role = $this->role($roleName);
            if ($role && $role->hasPermissionTo($approve)) {
                $role->revokePermissionTo($approve);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $approve = Permission::query()->where('name', 'syllabus.approve_adjustment')->where('guard_name', 'web')->first();
        if ($approve) {
            foreach (['academic_staff', 'manager'] as $roleName) {
                $this->role($roleName)?->givePermissionTo($approve);
            }
        }
        Permission::query()->where('name', 'big_test.approve')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function role(string $name): ?Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->first();
    }
};

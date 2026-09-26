<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * BA 26/09/2026.
     *
     * 1. "Phần kế toán cho Admin phân quyền linh hoạt": các thao tác kế toán trước đây kiểm tra cứng theo vai trò
     *    (chỉ Admin duyệt hoàn tiền / hủy hóa đơn, "kế toán không gán chi nhánh = kế toán tổng", dải số mặc định chỉ
     *    Admin / kế toán tổng) nay theo permission. Mặc định giữ đúng hành vi hiện tại:
     *    - refund_transfer.approve_refund (duyệt hoàn tiền): chỉ Admin.
     *    - refund_transfer.approve_transfer (duyệt chuyển nhượng), refund_transfer.reject (từ chối): mọi vai trò đang có
     *      refund_transfer.approve (Quản lý cơ sở, Kế toán). refund_transfer.approve nay chỉ còn khất nợ / bảo lưu.
     *    - invoice.approve_cancel: thu hồi khỏi mọi vai trò trừ Admin (trước đây Quản lý / Kế toán có quyền nhưng
     *      controller chặn "chỉ Admin" — hiệu lực không đổi).
     *    - tuition.all_branches, finance.all_branches, invoice_range.manage_default: chỉ Admin; kế toán đang không gán
     *      chi nhánh nào (kế toán tổng theo quy ước cũ) được cấp riêng qua Phân quyền cá nhân (override "Toàn hệ thống").
     * 2. "Học vụ là actor chính làm việc bên CRM nên full quyền, trừ delete": academic_staff nhận toàn bộ quyền CRM /
     *    test đầu vào trừ lead.delete, placement_test.delete (lùi giai đoạn vẫn chỉ Admin — A6 Q1, không phải permission).
     *
     * Quyền cấp riêng cho từng người (model_has_permissions / override) có sẵn không bị động tới. Xóa cache quyền.
     */
    private const NEW_PERMISSIONS = [
        'tuition.all_branches',
        'finance.all_branches',
        'invoice_range.manage_default',
        'refund_transfer.approve_refund',
        'refund_transfer.approve_transfer',
        'refund_transfer.reject',
    ];

    private const HEAD_OFFICE_PERMISSIONS = ['tuition.all_branches', 'finance.all_branches', 'invoice_range.manage_default'];

    private const ACADEMIC_STAFF_CRM = [
        'lead.view', 'lead.create', 'lead.update', 'lead.assign', 'lead.convert', 'lead.mark_lost',
        'promotion.manage',
        'entrance_test.view', 'entrance_test.send', 'entrance_test.grade',
        'placement_test.view', 'placement_test.create', 'placement_test.update', 'placement_test.send',
        'placement_test.grade', 'placement_test.distribute',
    ];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = $this->role('admin');
        $admin?->givePermissionTo(self::NEW_PERMISSIONS);

        // Chuyển nhượng / từ chối: giữ nguyên cho mọi vai trò đang duyệt được hồ sơ hoàn / chuyển / khất nợ / bảo lưu.
        $approve = Permission::findOrCreate('refund_transfer.approve', 'web');
        foreach ($approve->roles()->get() as $role) {
            $role->givePermissionTo(['refund_transfer.approve_transfer', 'refund_transfer.reject']);
        }

        // Duyệt hủy hóa đơn: controller cũ chỉ cho Admin → thu hồi quyền "treo" ở vai trò khác để màn Vai trò phản ánh đúng.
        $approveCancel = Permission::findOrCreate('invoice.approve_cancel', 'web');
        foreach ($approveCancel->roles()->get() as $role) {
            if ($role->name !== 'admin') {
                $role->revokePermissionTo($approveCancel);
            }
        }

        // Học vụ: toàn quyền CRM trừ xóa.
        if ($academicStaff = $this->role('academic_staff')) {
            foreach (self::ACADEMIC_STAFF_CRM as $name) {
                Permission::findOrCreate($name, 'web');
            }
            $academicStaff->givePermissionTo(self::ACADEMIC_STAFF_CRM);
        }

        // Kế toán tổng theo quy ước cũ (vai trò Kế toán, không gán chi nhánh chính lẫn phụ) → cấp riêng quyền mọi chi nhánh.
        if ($this->role('accountant') && DB::getSchemaBuilder()->hasTable('user_permission_overrides')) {
            $headAccountants = User::query()->role('accountant')
                ->whereNull('branch_id')
                ->whereDoesntHave('branches')
                ->get();
            foreach ($headAccountants as $user) {
                foreach (self::HEAD_OFFICE_PERMISSIONS as $name) {
                    [$module, $action] = explode('.', $name, 2);
                    $exists = UserPermissionOverride::query()->where('user_id', $user->id)
                        ->where('module', $module)->where('action', $action)->exists();
                    if (! $exists) {
                        UserPermissionOverride::create([
                            'user_id' => $user->id, 'module' => $module, 'action' => $action, 'allow' => true,
                            'scope_type' => UserPermissionOverride::SCOPE_ALL, 'scope_id' => null, 'created_by' => null,
                        ]);
                    }
                }
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (['manager', 'accountant'] as $roleName) {
            $this->role($roleName)?->givePermissionTo(Permission::findOrCreate('invoice.approve_cancel', 'web'));
        }

        if ($academicStaff = $this->role('academic_staff')) {
            foreach (['lead.create', 'lead.update', 'lead.assign', 'lead.convert', 'lead.mark_lost', 'promotion.manage',
                'placement_test.create', 'placement_test.update', 'placement_test.send', 'placement_test.distribute'] as $name) {
                if ($academicStaff->hasPermissionTo($name)) {
                    $academicStaff->revokePermissionTo($name);
                }
            }
        }

        foreach (self::HEAD_OFFICE_PERMISSIONS as $name) {
            [$module, $action] = explode('.', $name, 2);
            UserPermissionOverride::query()->where('module', $module)->where('action', $action)->whereNull('created_by')->delete();
        }

        Permission::query()->whereIn('name', self::NEW_PERMISSIONS)->where('guard_name', 'web')->delete();

        $registrar->forgetCachedPermissions();
    }

    private function role(string $name): ?Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->first();
    }
};

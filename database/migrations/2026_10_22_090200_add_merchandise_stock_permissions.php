<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền Tồn kho hàng hóa theo chi nhánh (config/permission_catalog.php → merchandise_stock). Admin có mọi quyền qua
 * Super Admin. Quản lý cơ sở, Học vụ, Kế toán: xem tồn kho + nhập kho / kiểm kê ở chi nhánh mình.
 * Học vụ được tạo yêu cầu hủy hóa đơn (ghi sai số trên hóa đơn giấy thu tiền mặt thì phải hủy số đó); duyệt hủy vẫn
 * theo quyền invoice.approve_cancel (mặc định chỉ Admin).
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch'],
        'academic_staff' => ['merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch', 'invoice.request_cancel'],
        'accountant' => ['merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch'],
    ];

    private const PERMISSIONS = ['merchandise_stock.view', 'merchandise_stock.manage', 'merchandise_stock.scope_branch', 'merchandise_stock.scope_all'];

    public function up(): void
    {
        foreach ([...self::PERMISSIONS, 'invoice.request_cancel'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            foreach ($permissions as $name) {
                if ($role && ! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', 'academic_staff')->where('guard_name', 'web')->first()?->revokePermissionTo('invoice.request_cancel');
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

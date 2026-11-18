<?php

use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TK-2026-0153: finance.view cũ gồm cả xem sổ khoản chi, xem báo cáo doanh thu tạm tính và (qua tuition.create) thêm /
 * sửa / xóa khoản chi. Tách thành 3 quyền độc lập; mọi người đang có finance.view giữ nguyên khả năng hiện có:
 *  - finance.view_revenue_report: cấp cho mọi vai trò / người đang có finance.view (override cũng được sao chép).
 *  - finance.manage: cấp cho vai trò / người đang có finance.view VÀ tuition.create (đúng điều kiện được ghi khoản chi
 *    trước đây). Người chỉ có finance.view (chỉ xem) không được cấp.
 */
return new class extends Migration
{
    private const NEW = ['finance.manage', 'finance.view_revenue_report'];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (self::NEW as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if (! $role->hasPermissionTo('finance.view')) {
                continue;
            }
            $role->givePermissionTo('finance.view_revenue_report');
            if ($role->hasPermissionTo('tuition.create')) {
                $role->givePermissionTo('finance.manage');
            }
        }

        // Quyền gán trực tiếp cho người dùng (model_has_permissions).
        foreach (User::query()->permission('finance.view')->get() as $user) {
            $user->givePermissionTo('finance.view_revenue_report');
            if ($user->hasPermissionTo('tuition.create')) {
                $user->givePermissionTo('finance.manage');
            }
        }

        // Phân quyền cá nhân (override): sao chép finance.view -> finance.view_revenue_report (cả cho phép lẫn chặn).
        if (Schema::hasTable('user_permission_overrides')) {
            $copy = fn (UserPermissionOverride $o, string $action) => UserPermissionOverride::query()->firstOrCreate(
                ['user_id' => $o->user_id, 'module' => 'finance', 'action' => $action, 'scope_type' => $o->scope_type, 'scope_id' => $o->scope_id],
                ['allow' => $o->allow, 'created_by' => null],
            );
            foreach (UserPermissionOverride::query()->where('module', 'finance')->where('action', 'view')->get() as $o) {
                $copy($o, 'view_revenue_report');
                if ($o->allow && User::query()->find($o->user_id)?->hasModuleAction('tuition', 'create')) {
                    $copy($o, 'manage');
                }
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('user_permission_overrides')) {
            UserPermissionOverride::query()->where('module', 'finance')
                ->whereIn('action', ['manage', 'view_revenue_report'])->whereNull('created_by')->delete();
        }
        Permission::query()->whereIn('name', self::NEW)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

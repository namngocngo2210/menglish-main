<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Order học liệu: cấp quyền mặc định cho hệ thống đang chạy (cài mới lấy từ config/access.php).
 * Giáo viên tạo order; Học vụ + Quản lý xử lý đạo cụ / in ấn / GVNN theo chi nhánh; Trưởng Học thuật xử lý học liệu
 * học thuật. Admin qua Gate::before.
 */
return new class extends Migration
{
    private const GRANTS = [
        'teacher' => ['material_order.create', 'material_order.scope_own'],
        'teacher_fulltime' => ['material_order.create', 'material_order.scope_own'],
        'teacher_parttime' => ['material_order.create', 'material_order.scope_own'],
        'manager' => ['material_order.view_all', 'material_order.process_ops', 'material_order.scope_branch'],
        'academic_staff' => ['material_order.view_all', 'material_order.process_ops', 'material_order.scope_branch'],
        'academic_lead' => ['material_order.view_all', 'material_order.process_academic', 'material_order.scope_all'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            foreach ($permissions as $name) {
                $permission = Permission::findOrCreate($name, 'web');
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::GRANTS as $roleName => $permissions) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo($permissions);
        }
        Permission::query()->where('name', 'like', 'material_order.%')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

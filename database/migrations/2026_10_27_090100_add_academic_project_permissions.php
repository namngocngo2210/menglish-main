<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền module "Dự án học thuật" (config/permission_catalog.php → academic_project). Admin có mọi quyền qua Super Admin.
 * Trưởng Học thuật: tạo / quản lý mọi dự án + báo cáo. Quản lý cơ sở: xem mọi dự án + báo cáo. Học vụ, giáo viên, trợ giảng:
 * xem và cập nhật tiến độ dự án mình tham gia.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'academic_project.view',
        'academic_project.view_all',
        'academic_project.manage',
    ];

    private const GRANTS = [
        'academic_lead' => self::PERMISSIONS,
        'manager' => ['academic_project.view', 'academic_project.view_all'],
        'academic_staff' => ['academic_project.view'],
        'teacher' => ['academic_project.view'],
        'teacher_fulltime' => ['academic_project.view'],
        'teacher_parttime' => ['academic_project.view'],
        'assistant' => ['academic_project.view'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
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
        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

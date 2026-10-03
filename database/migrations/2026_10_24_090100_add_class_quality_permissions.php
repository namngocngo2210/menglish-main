<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quyền module "Dự giờ & chất lượng lớp" (config/permission_catalog.php → class_quality). Admin có mọi quyền qua Super Admin.
 * Quản lý cơ sở: mọi thao tác. Học vụ: dự giờ vận hành, checklist học phí & feedback. Học thuật: đánh giá dự giờ học thuật,
 * báo cáo họp giáo viên. Lớp hiển thị theo phạm vi Lớp học (ClassModel::visibleTo).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'class_quality.view',
        'class_quality.observe_operations',
        'class_quality.observe_academic',
        'class_quality.checklist',
        'class_quality.teacher_meeting',
    ];

    private const GRANTS = [
        'manager' => self::PERMISSIONS,
        'academic_staff' => ['class_quality.view', 'class_quality.observe_operations', 'class_quality.checklist'],
        'academic_lead' => ['class_quality.view', 'class_quality.observe_academic', 'class_quality.teacher_meeting'],
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

<?php

namespace App\Helpers;

use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Nhãn hiển thị cho module / quyền / vai trò. Nhãn quyền đọc từ danh mục config/permission_catalog.php
 * (PermissionCatalog); nhãn vai trò: tên hiển thị Admin đặt ở màn Vai trò (roles.label), thiếu thì nhãn mặc định.
 */
class AclHelper
{
    public static function moduleLabel(string $module): string
    {
        return PermissionCatalog::moduleLabel($module);
    }

    public static function actionLabel(string $permissionName): string
    {
        return PermissionCatalog::label($permissionName);
    }

    public static function permissionDescription(string $permissionName): string
    {
        return PermissionCatalog::description($permissionName);
    }

    /** Nhãn đầy đủ mặc định của các vai trò hệ thống. */
    private const ROLE_LABELS = [
        'admin' => 'Quản trị viên Cấp cao (Admin)',
        'manager' => 'Giám đốc / Quản lý Cơ sở (Manager)',
        'accountant' => 'Kế toán Trưởng & Thu ngân (Accountant)',
        'academic_lead' => 'Học thuật độc lập (Academic Lead)',
        'academic' => 'Học thuật độc lập (Academic Lead)',
        'academic_staff' => 'Học vụ (Academic Staff)',
        'sales_consultant' => 'Chuyên viên Tư vấn Tuyển sinh (Sales)',
        'teacher' => 'Giáo viên Giảng dạy (Teacher)',
        'teacher_fulltime' => 'Giáo viên Fulltime',
        'teacher_parttime' => 'Giáo viên Parttime',
        'assistant' => 'Trợ giảng (Teaching Assistant)',
        'student' => 'Học viên (Student)',
    ];

    /** Nhãn ngắn mặc định (bảng, ô chọn người nhận) theo mockup: "Admin", "Học thuật", "Kế toán"… */
    private const SHORT_ROLE_LABELS = [
        'admin' => 'Admin',
        'manager' => 'Quản lý cơ sở',
        'accountant' => 'Kế toán',
        'academic_lead' => 'Học thuật',
        'academic' => 'Học thuật',
        'academic_staff' => 'Học vụ',
        'sales_consultant' => 'Tư vấn viên',
        'teacher' => 'Giáo viên',
        'teacher_fulltime' => 'Giáo viên Full-time',
        'teacher_parttime' => 'Giáo viên Part-time',
        'assistant' => 'Trợ giảng',
        'student' => 'Học viên',
    ];

    public static function roleLabel(string $roleName): string
    {
        return self::customRoleLabel($roleName) ?? self::ROLE_LABELS[$roleName] ?? $roleName;
    }

    public static function shortRoleLabel(string $roleName): string
    {
        return self::customRoleLabel($roleName) ?? self::SHORT_ROLE_LABELS[$roleName] ?? $roleName;
    }

    /** Vai trò có sẵn của hệ thống (có nhãn mặc định). */
    public static function isSystemRole(string $roleName): bool
    {
        return array_key_exists($roleName, config('access.roles', []));
    }

    /** Tên hiển thị Admin đặt cho vai trò (cột roles.label), nạp 1 lần / request. */
    private static function customRoleLabel(string $roleName): ?string
    {
        if (! app()->bound('rbac.role_labels')) {
            $labels = [];
            try {
                if (Schema::hasColumn('roles', 'label')) {
                    $labels = Role::query()->whereNotNull('label')->where('label', '!=', '')->pluck('label', 'name')->all();
                }
            } catch (\Throwable) {
                $labels = [];
            }
            app()->instance('rbac.role_labels', $labels);
        }

        return app('rbac.role_labels')[$roleName] ?? null;
    }
}

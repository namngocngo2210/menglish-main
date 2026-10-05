<?php

namespace App\Helpers;

use App\Support\PermissionCatalog;
use App\Support\Roles;
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

    /**
     * Danh sách [tên vai trò => nhãn] cho ô "Vai trò & Chức vụ" (form Thêm/Sửa nhân sự): các vai trò người thao tác được
     * phép gán, cộng thêm vai trò hiện tại của nhân sự (nếu không còn gán được) để form sửa không làm mất vai trò đang có.
     */
    public static function primaryRoleOptions(iterable $assignableRoles, ?string $currentRole = null): array
    {
        $options = [];
        foreach (collect($assignableRoles)->all() as $role) {
            $options[$role] = self::roleLabel($role);
        }
        if ($currentRole !== null && $currentRole !== '' && ! isset($options[$currentRole])) {
            $options[$currentRole] = self::roleLabel($currentRole);
        }

        return $options;
    }

    public static function roleLabel(string $roleName): string
    {
        return self::customRoleLabel($roleName) ?? Roles::LABELS[$roleName] ?? $roleName;
    }

    public static function shortRoleLabel(string $roleName): string
    {
        return self::customRoleLabel($roleName) ?? Roles::SHORT_LABELS[$roleName] ?? $roleName;
    }

    /** Vai trò có sẵn của hệ thống (bộ vai trò cố định, App\Support\Roles). */
    public static function isSystemRole(string $roleName): bool
    {
        return Roles::isFixed($roleName);
    }

    /** Tên hiển thị Admin đặt cho vai trò (cột roles.label), nạp 1 lần / request. */
    private static function customRoleLabel(string $roleName): ?string
    {
        if (! app()->bound('rbac.role_labels')) {
            $labels = [];
            try {
                $labels = Role::query()->whereNotNull('label')->where('label', '!=', '')->pluck('label', 'name')->all();
            } catch (\Throwable) {
                $labels = [];
            }
            app()->instance('rbac.role_labels', $labels);
        }

        return app('rbac.role_labels')[$roleName] ?? null;
    }
}

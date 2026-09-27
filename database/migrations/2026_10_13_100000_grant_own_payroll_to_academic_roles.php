<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Học vụ và Học thuật là nhân sự full-time có phiếu lương (PayrollFormulaService::profile) nhưng thiếu quyền
 * payroll.view_own nên không thấy "Lương của tôi" ở menu / trang cá nhân. Cấp quyền xem lương của chính mình.
 */
return new class extends Migration
{
    private const ROLES = ['academic_staff', 'academic_lead'];

    private const PERMISSIONS = ['payroll.view_own', 'payroll.scope_own'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (self::ROLES as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->revokePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

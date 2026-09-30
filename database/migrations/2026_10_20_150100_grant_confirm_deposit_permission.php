<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Cấp quyền tuition.confirm_deposit (xác nhận đã nộp tiền thu về TK công ty) cho Kế toán; Admin qua Gate::before. */
return new class extends Migration
{
    private const ROLES = ['accountant'];

    public function up(): void
    {
        $permission = Permission::findOrCreate('tuition.confirm_deposit', 'web');
        foreach (self::ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $roleName) {
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->revokePermissionTo('tuition.confirm_deposit');
        }
        Permission::query()->where('name', 'tuition.confirm_deposit')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

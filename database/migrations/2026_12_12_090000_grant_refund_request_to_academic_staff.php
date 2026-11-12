<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TK-2026-0101 (BA 12/11/2026): Học vụ lập yêu cầu khất nợ / chuyển nhượng / hoàn tiền / bảo lưu (Admin duyệt).
 * Chỉ cấp quyền lập yêu cầu; mọi quyền duyệt / từ chối vẫn chỉ Admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('refund_transfer.request', 'web');
        $role = Role::query()->where('name', Roles::ACADEMIC_STAFF)->where('guard_name', 'web')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', Roles::ACADEMIC_STAFF)->where('guard_name', 'web')->first()?->revokePermissionTo('refund_transfer.request');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

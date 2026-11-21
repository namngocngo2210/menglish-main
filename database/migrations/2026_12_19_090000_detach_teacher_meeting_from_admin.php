<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** TK-2026-0161: Admin is read-only on teacher-meeting reports; detach the old grant. Report data is untouched. */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->where('name', 'class_quality.teacher_meeting')->where('guard_name', 'web')->first();
        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();

        if ($permission && $admin && $admin->hasPermissionTo($permission)) {
            $admin->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty: re-granting would restore the bug.
    }
};

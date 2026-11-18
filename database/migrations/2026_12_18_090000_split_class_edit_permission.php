<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TK-2026-0148: only Học vụ + Admin create/edit classes. New `class.edit` (edit info, change teachers, toggle status) goes to
 * every role that has class.update EXCEPT Học thuật, which keeps class.update (dashboards/reports) and loses create/delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $edit = Permission::findOrCreate('class.edit', 'web');

        Role::query()->where('guard_name', 'web')->where('name', '!=', Roles::ACADEMIC_LEAD)
            ->whereHas('permissions', fn ($q) => $q->where('name', 'class.update')->where('guard_name', 'web'))
            ->get()
            ->each(fn (Role $role) => $role->hasPermissionTo($edit) || $role->givePermissionTo($edit));

        Role::query()->where('guard_name', 'web')->where('name', Roles::ACADEMIC_LEAD)->first()
            ?->revokePermissionTo(Permission::query()->where('guard_name', 'web')
                ->whereIn('name', ['class.create', 'class.delete', 'class.edit'])->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'class.edit')->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

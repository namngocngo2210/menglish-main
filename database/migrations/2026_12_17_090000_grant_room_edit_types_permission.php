<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** TK-2026-0144: Allow academic staff to edit types and preserve existing type administrators. */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('room.edit_types', 'web');
        $roles = Role::query()->where('guard_name', 'web')
            ->where(fn ($query) => $query->where('name', Roles::ACADEMIC_STAFF)
                ->orWhereHas('permissions', fn ($permissions) => $permissions
                    ->where('name', 'room.manage_types')->where('guard_name', 'web')))
            ->get();

        foreach ($roles as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'room.edit_types')->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

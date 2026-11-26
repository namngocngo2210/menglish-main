<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('student.opening_sessions', 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', [Roles::ACADEMIC_STAFF, Roles::ACCOUNTANT])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'student.opening_sessions')->where('guard_name', 'web')->first()?->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

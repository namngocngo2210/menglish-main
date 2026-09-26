<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Tạo mọi permission của danh mục (config/permission_catalog.php) + quyền gán vai trò "user.assign_role.<vai trò>"
     * cho các vai trò mặc định và vai trò đang có. CHỈ THÊM (findOrCreate) — không xóa, không đổi gán quyền.
     */
    public function run(): void
    {
        $roles = collect(array_keys(config('access.roles', [])))
            ->merge(Role::query()->where('guard_name', 'web')->pluck('name'))
            ->unique();

        foreach (PermissionCatalog::allPermissions($roles) as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}

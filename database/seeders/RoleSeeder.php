<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds. Tạo role mặc định và gán permission theo
     * config/access.php (hỗ trợ wildcard "module.*" và "*" cho admin).
     * Idempotent và không xóa permission đã được chỉnh tay thêm ngoài config
     * (chỉ đảm bảo tập permission theo config luôn được gán).
     */
    public function run(): void
    {
        $allPermissionNames = Permission::query()->pluck('name');

        foreach (config('access.roles', []) as $roleName => $patterns) {
            $role = Role::findOrCreate($roleName, 'web');

            $resolved = collect($patterns)->flatMap(function (string $pattern) use ($allPermissionNames) {
                if ($pattern === '*') {
                    return $allPermissionNames;
                }

                if (str_ends_with($pattern, '.*')) {
                    $module = substr($pattern, 0, -2);

                    return $allPermissionNames->filter(
                        fn (string $name) => str_starts_with($name, "{$module}.")
                    );
                }

                return [$pattern];
            })->unique()->values();

            $role->syncPermissions($resolved);
        }
    }
}

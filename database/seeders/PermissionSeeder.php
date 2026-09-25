<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds. Đọc toàn bộ permission từ config/access.php,
     * tạo theo format "module.action" cho guard "web". Idempotent.
     */
    public function run(): void
    {
        foreach (config('access.permissions', []) as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'web');
            }
        }
    }
}

<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Seeder;
use Tests\Support\TestOnlyRoles;

/** Dữ liệu gốc của database test: vai trò + quyền (nạp 1 lần khi migrate), thêm vai trò tùy chỉnh chỉ dùng trong test. */
class TestBaseSeeder extends Seeder
{
    public function run(): void
    {
        // Vai trò tùy chỉnh dùng riêng trong test (không thuộc 9 vai trò cố định của hệ thống): tạo trước để sinh quyền gán vai trò.
        TestOnlyRoles::createRoles();
        $this->call([PermissionSeeder::class, RoleSeeder::class]);
        TestOnlyRoles::seed();
    }
}

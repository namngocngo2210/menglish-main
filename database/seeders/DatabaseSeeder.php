<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Chỉ seed dữ liệu nền tảng/hệ thống (idempotent, an toàn chạy lại
     * nhiều lần kể cả trên production) — không đụng dữ liệu nghiệp vụ
     * (leads, phiếu thu, bảng lương...). Thứ tự phải tuân theo phụ thuộc:
     * Branch → Permission → Role (cần permission) → SystemCategory →
     * Holiday (cần branch) → User (cần branch + role).
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            SystemCategorySeeder::class,
            HolidaySeeder::class,
            UserSeeder::class,
            CrmCustomerSeeder::class,
            MasterEntitySeeder::class,
            WorkTaskSeeder::class,
        ]);
    }
}

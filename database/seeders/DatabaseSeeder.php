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
     *
     * Ngoại lệ: DemoPhase1Seeder (dữ liệu nghiệp vụ mẫu) chỉ chạy ở local/testing/staging hoặc SEED_DEMO=true.
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

        // Dữ liệu demo Phase 1 (khách đủ các bước, lớp + buổi học, học thử, chốt, lớp chờ): chỉ môi trường
        // không phải production, hoặc bật rõ bằng SEED_DEMO=true.
        if (app()->environment(['local', 'testing', 'staging']) || filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoPhase1Seeder::class);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Dữ liệu demo phủ toàn hệ thống (cả case đẹp lẫn xấu) cho các màn chưa có dữ liệu sau db:seed + demo lương: chuyển cơ sở lead,
 * khuyến mãi, SLA, lớp / học viên kết thúc hoặc nghỉ, giáo trình, khảo sát, báo cáo nhân sự, dự giờ, tuyển dụng, kho vật phẩm,
 * các phiếu bị từ chối, kỳ lương đã chi trả... Mỗi phần là một seeder riêng, tự bỏ qua khi đã chạy hoặc thiếu dữ liệu nền.
 *
 * Gọi từ cuối DemoPayrollSeeder (php artisan demo:luong, seed "demo-luong" khi deploy). Chỉ thêm dữ liệu, không sửa bản ghi có sẵn.
 */
class DemoCoverageSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoCoverageCrmSeeder::class,
            DemoCoverageTrainingSeeder::class,
            DemoCoverageQualitySeeder::class,
            DemoCoverageOpsSeeder::class,
            DemoCoverageFinanceSeeder::class,
        ]);
    }
}

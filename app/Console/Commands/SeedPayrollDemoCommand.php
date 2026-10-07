<?php

namespace App\Console\Commands;

use Database\Seeders\DemoPayrollSeeder;
use Illuminate\Console\Command;

/**
 * Tạo dữ liệu mẫu cho phần lương (chấm công điện thoại, đơn xin duyệt, giờ dạy, biên bản đi muộn, KPI mọi vai trò, bảng lương
 * kỳ đang soát) để xem các màn có nội dung. Chỉ chạy khi gọi tay, không chạy khi deploy; dựng trên tài khoản mẫu và dữ liệu demo
 * Phase 1–4 (php artisan db:seed trên CSDL demo). Production phải thêm --force.
 */
class SeedPayrollDemoCommand extends Command
{
    protected $signature = 'demo:luong {--force : Cho phép chạy khi APP_ENV=production}';

    protected $description = 'Tạo dữ liệu mẫu phần lương: chấm công, đơn xin duyệt, giờ dạy, KPI, bảng lương kỳ này';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Đang ở production: dữ liệu mẫu sẽ trộn vào chấm công, KPI và bảng lương thật. Chỉ chạy trên CSDL demo, hoặc thêm --force nếu chắc chắn.');

            return self::FAILURE;
        }

        return $this->call('db:seed', ['--class' => DemoPayrollSeeder::class, '--force' => true]);
    }
}

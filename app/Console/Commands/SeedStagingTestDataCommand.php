<?php

namespace App\Console\Commands;

use Database\Seeders\StagingTestDataSeeder;
use Illuminate\Console\Command;

/** TK-2026-0114: dữ liệu test cho các ca BA bị chặn. Chỉ staging/local, idempotent; xem docs/staging-test-data.md. */
class SeedStagingTestDataCommand extends Command
{
    protected $signature = 'staging:seed-test-data {--password= : Mật khẩu tài khoản test (mặc định Test@2026)} {--birthday= : Ngày sinh nhật của HS-TB-04 (Y-m-d hoặc m-d; mặc định hôm nay)}';

    protected $description = 'Tạo dữ liệu test cho các ca BA bị chặn (staging only, chạy lại an toàn)';

    public function handle(): int
    {
        if (! in_array(app()->environment(), StagingTestDataSeeder::ALLOWED_ENVIRONMENTS, true)) {
            $this->error('Lệnh này chỉ chạy trên staging (APP_ENV='.app()->environment().').');

            return self::FAILURE;
        }

        try {
            app(StagingTestDataSeeder::class)->configure($this->option('password'), $this->option('birthday'))->setCommand($this)->run();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

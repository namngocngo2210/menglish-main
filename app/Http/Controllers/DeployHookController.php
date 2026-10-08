<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\DemoPayrollSeeder;
use Database\Seeders\ProductionBootstrapSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Hook chạy các lệnh sau deploy trên shared hosting không có SSH (DirectAdmin):
 * migrate, storage:link, làm mới cache. Chỉ bật khi đặt DEPLOY_HOOK_TOKEN trong .env;
 * gọi bằng POST kèm header X-Deploy-Token. Không có token / sai token → 404 (ẩn route).
 */
class DeployHookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('app.deploy_hook_token');
        $given = (string) $request->header('X-Deploy-Token', '');
        abort_if($expected === '' || strlen($expected) < 32 || ! hash_equals($expected, $given), 404);

        // Chỉ giới hạn thời gian khi chạy qua web: set_time_limit() trong CLI (test) sẽ giết cả tiến trình sau 300 giây.
        if (! app()->runningInConsole()) {
            @set_time_limit(300);
        }
        $steps = [];
        $run = function (string $command, array $args = []) use (&$steps) {
            try {
                $code = Artisan::call($command, $args);
                $output = trim(Artisan::output());
            } catch (Throwable $e) {
                $code = 1;
                $output = $e::class.': '.$e->getMessage();
            }
            $steps[] = ['command' => $command, 'exit' => $code, 'output' => $output];

            return $code;
        };

        // Chỉ xoá cache dạng file trước migrate; cache:clear (store database) cần bảng "cache" — chạy sau migrate.
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            $run($command);
        }
        $migrateCode = $run('migrate', ['--force' => true]);
        if ($migrateCode === 0) {
            $run('cache:clear');
        }

        // Seed:
        //  - "bootstrap": vai trò, quyền, danh mục + 1 Admin từ .env — chỉ khi database CHƯA có người dùng (cài mới, kể cả production).
        //  - "demo": toàn bộ DatabaseSeeder (tài khoản & dữ liệu demo).
        //  - "demo-luong": dữ liệu mẫu phần lương (php artisan demo:luong), chạy sau "demo".
        //  Hai loại demo bị chặn trên production, trừ khi deploy tích "demo_on_production" (production chưa dùng thật)
        //  VÀ .env đặt SEED_DEFAULT_PASSWORD riêng: tài khoản demo (kể cả Admin) không được mang mật khẩu mặc định ra internet.
        $seed = (string) $request->input('seed', '');
        $demoOnProduction = $request->boolean('allow_production_demo');
        if ($migrateCode === 0 && in_array($seed, ['bootstrap', 'demo', 'demo-luong', '1'], true)) {
            if ($seed === 'bootstrap') {
                if (User::query()->exists()) {
                    $steps[] = ['command' => 'db:seed', 'exit' => 1, 'output' => 'Bỏ qua: database đã có người dùng, không khởi tạo lại.'];
                } else {
                    $run('db:seed', ['--class' => ProductionBootstrapSeeder::class, '--force' => true]);
                }
            } elseif (app()->environment('production') && ! $demoOnProduction) {
                $steps[] = ['command' => 'db:seed', 'exit' => 1, 'output' => 'Bị chặn: không chạy seed demo trên production (tích demo_on_production khi deploy nếu production chưa dùng thật).'];
            } elseif (app()->environment('production') && ! $this->hasOwnSeedPassword()) {
                $steps[] = ['command' => 'db:seed', 'exit' => 1, 'output' => 'Bị chặn: đặt SEED_DEFAULT_PASSWORD (≥ 10 ký tự, khác mặc định) trong .env trước khi seed demo trên production.'];
            } elseif ($seed === 'demo-luong') {
                $run('db:seed', ['--class' => DemoPayrollSeeder::class, '--force' => true]);
            } else {
                // DatabaseSeeder chỉ đổ dữ liệu nghiệp vụ mẫu (DemoPhase1–4) ngoài production hoặc khi bật seed_demo.
                if ($demoOnProduction) {
                    config(['app.seed_demo' => true]);
                }
                $run('db:seed', ['--force' => true]);
            }
        }

        if (! File::exists(public_path('storage'))) {
            $run('storage:link');
        }

        // Chỉ ghi cache khi chạy qua web trên hosting (không ghi khi chạy test / CLI trên máy dev).
        if ($migrateCode === 0 && ! app()->runningInConsole()) {
            $run('config:cache');
            $run('route:cache');
            $run('view:cache');
        }

        $ok = collect($steps)->every(fn ($step) => $step['exit'] === 0);

        return response()->json(['ok' => $ok, 'steps' => $steps], $ok ? 200 : 500);
    }

    private function hasOwnSeedPassword(): bool
    {
        $password = (string) config('access.seed_password');

        return strlen($password) >= 10 && $password !== 'Password123!';
    }
}

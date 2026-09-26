<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

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

        @set_time_limit(300);
        $steps = [];
        $run = function (string $command, array $args = []) use (&$steps) {
            $code = Artisan::call($command, $args);
            $steps[] = ['command' => $command, 'exit' => $code, 'output' => trim(Artisan::output())];

            return $code;
        };

        $run('optimize:clear');
        $migrateCode = $run('migrate', ['--force' => true]);

        if (! File::exists(public_path('storage'))) {
            $run('storage:link');
        }

        if ($migrateCode === 0) {
            $run('config:cache');
            $run('route:cache');
            $run('view:cache');
        }

        $ok = collect($steps)->every(fn ($step) => $step['exit'] === 0);

        return response()->json(['ok' => $ok, 'steps' => $steps], $ok ? 200 : 500);
    }
}

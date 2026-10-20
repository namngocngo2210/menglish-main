<?php

namespace App\Http\Middleware;

use App\Support\DataScopeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bật lọc dữ liệu tự động (App\Models\Concerns\ScopedToViewer) cho cả request web, kể cả route model binding.
 * Đứng đầu nhóm "web" nên chạy trước SubstituteBindings. Tắt lại khi request kết thúc.
 */
class EnforceDataScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = DataScopeGuard::current();
        $guard->enforce();
        try {
            return $next($request);
        } catch (\Throwable $e) {
            $guard->enforce(false);
            throw $e;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        DataScopeGuard::current()->enforce(false);
    }
}

<?php

use App\Http\Controllers\DeployHookController;
use App\Http\Controllers\UploadedFileController;
use App\Http\Middleware\AuditOperationMiddleware;
use App\Http\Middleware\EnforceDataScope;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireInitialPasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Hook sau deploy cho shared hosting (DirectAdmin, không SSH): chỉ bật khi có DEPLOY_HOOK_TOKEN.
        // Không qua nhóm "web": session / throttle dùng bảng DB (sessions, cache) — chưa tồn tại khi cài mới,
        // mà chính hook là thứ chạy migrate tạo ra các bảng đó.
        then: function () {
            Route::post('_deploy/hook', DeployHookController::class)->name('deploy.hook');
            // File công khai trên R2 (UPLOAD_STORAGE=r2). Ngoài nhóm "web": mỗi ảnh/audio không mở phiên DB.
            Route::get('storage/{path}', [UploadedFileController::class, 'storage'])->where('path', '.*')->name('uploaded-files.storage');
            Route::get('uploads/{path}', [UploadedFileController::class, 'uploads'])->where('path', '.*')->name('uploaded-files.uploads');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Đổi / đặt lại mật khẩu thì các phiên đăng nhập cũ (thiết bị khác, phiên bị lộ) tự đăng xuất ở request kế tiếp.
        $middleware->authenticateSessions();
        $middleware->append(SecurityHeaders::class);
        // Chạy sau reverse proxy (Docker + Caddy): tin X-Forwarded-* để nhận đúng https / IP. Hosting không đặt biến này.
        if ($trustedProxies = getenv('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)));
        }
        // Lọc dữ liệu tự động theo người xem: đứng đầu nhóm để route model binding cũng bị lọc (404 ngoài phạm vi).
        $middleware->prependToGroup('web', EnforceDataScope::class);
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            RequireInitialPasswordChange::class,
            AuditOperationMiddleware::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->preventRequestForgery(except: [
            'hook/sepay-gateway/*',
            'api/sepay/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

<?php

use App\Http\Controllers\DeployHookController;
use App\Http\Middleware\AuditOperationMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireInitialPasswordChange;
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
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            RequireInitialPasswordChange::class,
            AuditOperationMiddleware::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'hook/sepay-gateway/*',
            'api/sepay/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

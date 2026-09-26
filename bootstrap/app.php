<?php

use App\Http\Middleware\AuditOperationMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireInitialPasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            RequireInitialPasswordChange::class,
            AuditOperationMiddleware::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            '_deploy/hook',
            'hook/sepay-gateway/*',
            'api/sepay/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

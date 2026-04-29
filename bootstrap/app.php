<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Auth\Http\Middleware\EnsureApiTokenAuthenticated;
use App\Modules\WhatsApp\Http\Middleware\EnsureInternalSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'api.token' => EnsureApiTokenAuthenticated::class,
            'wa.internal.secret' => EnsureInternalSecret::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

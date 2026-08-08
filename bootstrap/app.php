<?php

use App\Http\Middleware\AgencyScope;
use App\Http\Middleware\AuthenticateMultiGuard;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\HandleSvpCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway terminates TLS at its load balancer and forwards the original
        // scheme/host/port to the PHP backend. Unless we trust those headers,
        // Laravel falls back to the plain-HTTP backend connection and generates
        // http:// URLs (broken login form, insecure absolute links) whenever
        // APP_ENV isn't exactly "production" to trigger URL::forceScheme('https').
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'CheckPermission' => CheckPermission::class,
            'agency.scope' => AgencyScope::class,
            'auth.multi' => AuthenticateMultiGuard::class,
            'svp_cors' => HandleSvpCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

<?php

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
        $middleware->alias([
            'CheckPermission'    => \App\Http\Middleware\CheckPermission::class,
            'agency.scope'       => \App\Http\Middleware\AgencyScope::class,
            'auth.multi'         => \App\Http\Middleware\AuthenticateMultiGuard::class,
        ]);

        // Railway (and most PaaS platforms) terminate TLS at the edge and
        // forward plain HTTP to the container, passing along X-Forwarded-*
        // headers. Without trusting them, Laravel thinks every request is
        // HTTP, generates http:// URLs for routes/forms/AJAX calls, and
        // browsers block or flag those as insecure. Railway's edge IP isn't
        // fixed, so trust all proxies for the forwarding headers only.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

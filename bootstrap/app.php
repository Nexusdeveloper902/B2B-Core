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
        // Allow same-origin (dashboard) fetch calls to /api/* routes to
        // authenticate via the session, while device endpoints stay purely
        // stateless (reader Bearer tokens).
        $middleware->statefulApi();

        // Dashboard UI locale: session-based (EN / ES language switcher).
        // Appended so it runs AFTER StartSession.
        $middleware->web(append: [
            \App\Http\Middleware\SetWebLocale::class,
        ]);

        // API locale: resolve per-request from the Accept-Language header
        // (device-facing messages are localized English / Spanish).
        $middleware->api(prepend: [
            \App\Http\Middleware\SetApiLocale::class,
        ]);

        $middleware->alias([
            'reader.auth' => \App\Http\Middleware\ResolveReaderToken::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

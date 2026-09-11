<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveReaderToken;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetWebLocale;
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

        // Authenticated users who open a guest-only page (a bookmarked
        // /login, most commonly) go to their OWN dashboard. The framework
        // default sends everyone to /dashboard — staff-only, so students
        // used to land on a bare 403 with no way out.
        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();

            return $user && $user->isStudent()
                ? route('student.dashboard')
                : route('dashboard');
        });

        // Dashboard UI locale: session-based (EN / ES language switcher).
        // Appended so it runs AFTER StartSession.
        $middleware->web(append: [
            SetWebLocale::class,
            // TASK-030-A (ADR-044) — forced first-login rotation. Route-
            // named exemptions (login/logout/locale/password form) live
            // in the middleware; guests and unnamed routes pass through.
            EnsurePasswordChanged::class,
        ]);

        // API locale: resolve per-request from the Accept-Language header
        // (device-facing messages are localized English / Spanish).
        $middleware->api(prepend: [
            SetApiLocale::class,
        ]);

        // TASK-030-A — the rotation wall on the API surface too: a
        // flagged account driving /api/* (session or PAT) gets a
        // bilingual 403, never a redirect. Device endpoints carry no
        // user and pass through untouched.
        $middleware->api(append: [
            EnsurePasswordChanged::class,
        ]);

        $middleware->alias([
            'reader.auth' => ResolveReaderToken::class,
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-030-A (ADR-044) — forced first-login password rotation.
 *
 * Auto-provisioned student accounts ship with a shared, documented
 * initial password, so they carry users.must_change_password=true until
 * the owner rotates it. Flagged users may open only the password-change
 * form, logout, and the locale switcher — every other web page bounces
 * them to the form. JSON/API callers get a bilingual 403 instead of an
 * HTML redirect (a 302 to a Blade page would corrupt their contract).
 */
class EnsurePasswordChanged
{
    /** Route names a flagged user may still open. */
    private const EXEMPT = [
        'login',
        'logout',
        'locale.switch',
        'password.change',
        'password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Finding 2.1: the api group append runs BEFORE route
        // auth:sanctum, so $request->user() (web guard) is null for
        // Bearer-PAT callers — resolve sanctum on demand too, or
        // flagged PAT traffic walks straight through the wall.
        $user = $request->user() ?? $request->user('sanctum');

        // Guards memoize the user per app lifetime (test apps, Octane):
        // a snapshot taken before the flag flipped would lie. The wall
        // reads the flag from the database — one indexed PK read —
        // never from the memoized instance.
        $flagged = $user instanceof User
            && (bool) User::whereKey($user->getKey())->value('must_change_password');

        if (! $flagged) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // Unnamed routes (the 404 fallback) keep their verdict — the
        // wall only guards real pages, it never invents redirects.
        if ($routeName === null || in_array($routeName, self::EXEMPT, true)) {
            return $next($request);
        }

        // Finding 2.5: match the app's own JSON verdict (bootstrap
        // exceptions: api/* OR expectsJson) — a browser visit to an
        // api/* path must 403, never 302 to a Blade page.
        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, __('api.password_change_required'));
        }

        return redirect()->route('password.change')
            ->with('status', __('auth.must_change_password'));
    }
}

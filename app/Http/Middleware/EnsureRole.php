<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for dashboard/API users: `role:admin`, `role:teacher`, or
 * `role:admin,teacher` (any of). Unauthenticated requests pass through to
 * the auth middleware (which returns a JSON 401 for API clients).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request); // let auth:sanctum handle guests (401)
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, __('api.forbidden_role'));
        }

        return $next($request);
    }
}

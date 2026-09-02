<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * API localization: device-facing messages (error strings etc.) are
 * localized English/Spanish based on the Accept-Language header.
 *
 *   Accept-Language: es -> Spanish messages
 *   Accept-Language: en (or anything else) -> English messages
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));

        $locale = in_array($requested, (array) config('presence.locales', ['en']), true)
            ? $requested
            : config('app.locale', 'en');

        App::setLocale($locale);

        return $next($request);
    }
}

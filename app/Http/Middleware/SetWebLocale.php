<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web localization: the dashboard locale lives in the session.
 * Switched via GET /locale/{locale} (language switcher in the navbar).
 * Defaults to APP_LOCALE (en).
 */
class SetWebLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->session()->get('locale');
        $available = (array) config('presence.locales', ['en']);

        $locale = in_array($sessionLocale, $available, true)
            ? $sessionLocale
            : config('app.locale', 'en');

        App::setLocale($locale);

        return $next($request);
    }
}

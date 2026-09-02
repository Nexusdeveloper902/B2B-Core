<?php

namespace App\Http\Middleware;

use App\Models\Reader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device-side authentication: resolves the reader from the static Bearer
 * API key. The key IS the reader identity — client-supplied reader IDs are
 * never trusted (they would let any caller impersonate any reader).
 *
 * Works identically for Postman, curl, tests, and future ESP32 firmware.
 */
class ResolveReaderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.missing_bearer_token'),
            ], 401);
        }

        $reader = Reader::where('api_key', $token)->first();

        if ($reader === null) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.invalid_bearer_token'),
            ], 401);
        }

        $request->attributes->set('reader', $reader);

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Reader;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device-side authentication (TASK-043, ADR-062).
 *
 * Two schemes, one identity rule (the key is never trusted from the
 * client — it only PROVES itself):
 *
 * 1. Pulse-HMAC (the device path): `Authorization: Pulse-HMAC
 *    <kid>:<nonce>:<sig>` — per-request HMAC over method, path, nonce
 *    and body hash, keyed by the reader secret. The secret never rides
 *    the wire, so captured traffic cannot be replayed (single-use
 *    nonce) or retargeted (body-bound signature).
 * 2. Bearer (the bench path): the legacy static `api_key`, kept for
 *    Postman/curl/e2e scripts and killable via
 *    DEVICE_AUTH_ALLOW_LEGACY_BEARER=false (demo-day lockdown).
 *
 * Works identically for Postman, curl, tests, and ESP32 firmware.
 */
class ResolveReaderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Pulse-HMAC ')) {
            $result = app(DeviceRequestSigner::class)->verify($request, $header);

            if (! $result['ok']) {
                return $this->deny('api.invalid_device_signature');
            }

            $request->attributes->set('reader', $result['reader']);

            return $next($request);
        }

        if (! config('presence.device_auth.allow_legacy_bearer', true)) {
            return $this->deny('api.invalid_device_signature');
        }

        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.missing_bearer_token'),
            ], 401);
        }

        $reader = Reader::where('api_key', $token)->first();

        if ($reader === null) {
            return $this->deny('api.invalid_bearer_token');
        }

        $request->attributes->set('reader', $reader);

        return $next($request);
    }

    private function deny(string $key): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => __($key),
        ], 401);
    }
}

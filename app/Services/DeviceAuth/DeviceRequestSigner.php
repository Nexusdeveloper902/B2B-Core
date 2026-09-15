<?php

namespace App\Services\DeviceAuth;

use App\Models\Reader;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-043 — signed device authentication (supersedes ADR-002's
 * Bearer-only contract; see ADR-062).
 *
 * The static reader key never travels the wire anymore. Instead each
 * request carries `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>` where
 *   kid  = sha256(api_key)[0:16] — a PUBLIC key fingerprint, not a secret;
 *          no migration, no provisioning change, rotates with the key.
 *   nonce = 16–64 chars [A-Za-z0-9], generated per request (esp_random
 *          on device), single-use: the server `Cache::add`s it for 24 h
 *          and rejects any repeat — a captured request cannot be replayed.
 *   sig  = hex(HMAC-SHA256(api_key, "METHOD\npath[?query]\nnonce\nsha256hex(body)")).
 *   For JSON endpoints body is the raw request bytes. For multipart
 *   image endpoints (classify/capture) PHP never exposes the raw bytes
 *   (php://input is empty for multipart/form-data), so body is the
 *   deterministic canonical in multipartCanonical() — event_id plus the
 *   sha256 of the image bytes, which both sides can reconstruct.
 *
 * Nonce-only freshness is deliberate: the ESP32 has no wall clock
 * (millis() only, no NTP on a phone hotspot), so timestamp schemes are
 * unworkable here. Requests stay self-contained and IP-agnostic —
 * mDNS discovery and reconnect behavior are untouched. Endpoint-level
 * idempotency (classify/pair/redeem/meal-duplicate/classroom dedup)
 * is the second layer behind the nonce cache.
 *
 * This class owns BOTH sides of the contract: `sign()` builds what a
 * legitimate device (or the firmware's host tests) sends, `verify()`
 * checks what the middleware receives. One canonical string, one place.
 */
class DeviceRequestSigner
{
    /** Fingerprint length (hex chars of sha256(api_key)). */
    public const KID_LEN = 16;

    /** Nonce cache TTL — a captured signature stays dead at least this long. */
    public const NONCE_TTL_SECONDS = 86400;

    /** @return array{ok: true, reader: Reader}|array{ok: false, reason: 'malformed'|'unknown'|'replay'|'mismatch'} */
    public function verify(Request $request, string $header): array
    {
        $parts = explode(':', substr($header, strlen('Pulse-HMAC ')));

        if (count($parts) !== 3) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$kid, $nonce, $sig] = $parts;

        if (! ctype_xdigit($kid) || strlen($kid) !== self::KID_LEN
            || ! preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce)
            || ! ctype_xdigit($sig) || strlen($sig) !== 64) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $reader = Reader::all()->first(
            fn (Reader $r): bool => hash_equals(self::fingerprint($r->api_key), strtolower($kid))
        );

        if ($reader === null) {
            return ['ok' => false, 'reason' => 'unknown'];
        }

        $expected = self::sign($reader->api_key, $request->method(), self::requestPath($request), self::canonicalBody($request), $nonce);

        if (! hash_equals($expected, strtolower($sig))) {
            return ['ok' => false, 'reason' => 'mismatch'];
        }

        // Single-use nonce (Cache::add is set-if-absent: the loser of a
        // simultaneous identical replay answers 401, never double-executes).
        // Endpoint-level idempotency stays the second layer — see class doc.
        $claimed = Cache::add(
            'pulse-hmac-nonce:'.$reader->id.':'.$nonce,
            1,
            self::NONCE_TTL_SECONDS
        );

        if (! $claimed) {
            return ['ok' => false, 'reason' => 'replay'];
        }

        return ['ok' => true, 'reader' => $reader];
    }

    /**
     * Sign a request (legitimate devices, bench curl, tests).
     * $path is the origin-form path[?query] the device puts on the wire.
     */
    public static function sign(string $secret, string $method, string $path, string $body, string $nonce): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $nonce, $body), $secret);
    }

    /** Authorization header value for a reader + request. */
    public static function authorizationHeader(Reader $reader, string $method, string $path, string $body, string $nonce): string
    {
        return 'Pulse-HMAC '.self::fingerprint($reader->api_key).':'.$nonce.':'
            .self::sign($reader->api_key, $method, $path, $body, $nonce);
    }

    /** Public key fingerprint — safe to send in clear, reveals nothing about the key. */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, self::KID_LEN);
    }

    /** The exact string both sides sign — byte-for-byte contract (ADR-062). */
    public static function canonical(string $method, string $path, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".$path."\n".$nonce."\n".hash('sha256', $body);
    }

    /**
     * Multipart signing canonical — the `body` both sides sign for image
     * uploads (classify/capture). PHP never exposes raw multipart bytes
     * (php://input is empty), so the device signs this string instead of
     * the wire bytes, and verify() reconstructs it from the parsed upload.
     * Format is byte-exact on both sides (firmware CapturePayload mirrors it):
     *   classify: "event_id=<id>\nimage.sha256=<hex>"
     *   capture:  "image.sha256=<hex>"
     */
    public static function multipartCanonical(?string $eventId, string $imageSha256): string
    {
        $imageSha256 = strtolower($imageSha256);
        if ($eventId !== null && $eventId !== '') {
            return 'event_id='.(string) (int) $eventId."\n".'image.sha256='.$imageSha256;
        }

        return 'image.sha256='.$imageSha256;
    }

    /**
     * The `body` input to sign()/verify(): raw bytes for JSON, the
     * multipart canonical for image uploads (hasFile('image')).
     */
    public static function canonicalBody(Request $request): string
    {
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            if (is_array($file)) {
                $file = reset($file);
            }
            if ($file instanceof UploadedFile && $file->isValid()) {
                $hash = @hash_file('sha256', $file->getPathname());
                if (is_string($hash) && $hash !== '') {
                    $eventId = $request->input('event_id');

                    return self::multipartCanonical($eventId !== null ? (string) $eventId : null, $hash);
                }
            }
            // Invalid/missing file bytes: fail closed (never match a valid
            // device signature). Validation still owns the 422 for Bearer.
            $eventId = $request->input('event_id');

            return self::multipartCanonical($eventId !== null ? (string) $eventId : null, '');
        }

        return (string) $request->getContent();
    }

    private static function requestPath(Request $request): string
    {
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        return $query !== null && $query !== '' ? $path.'?'.$query : $path;
    }
}

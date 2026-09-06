<?php

namespace App\Services\Realtime;

/**
 * Realtime feed connection tokens (TASK-016, ADR-026).
 *
 * `userId.expiry.signature` where signature = HMAC-SHA256 over
 * "realtime:userId.expiry" keyed by APP_KEY. Purpose: let the
 * WebSocket process authenticate a dashboard user WITHOUT parsing
 * Laravel sessions (the WS socket lives on a different port, and
 * session internals are not a stable contract). The token carries
 * no data worth stealing beyond "a dashboard user may watch the
 * feed until T", expires in minutes, and is re-minted on reconnect
 * by the session-authed /realtime/token endpoint.
 */
final class RealtimeToken
{
    /**
     * Issue a token for a dashboard user id.
     */
    public static function issue(int $userId, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::defaultTtl();

        $payload = $userId.'.'.$expiresAt;

        return $payload.'.'.self::signature($payload);
    }

    /**
     * Verify a token; returns the user id, or null when malformed,
     * tampered, or expired.
     */
    public static function verify(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (\count($parts) !== 3) {
            return null;
        }
        [$userId, $expiresAt, $signature] = $parts;

        if (! ctype_digit($userId) || ! ctype_digit($expiresAt) || $userId === '0') {
            return null;
        }

        if (! hash_equals(self::signature($userId.'.'.$expiresAt), $signature)) {
            return null;
        }

        if ((int) $expiresAt < time()) {
            return null;
        }

        return (int) $userId;
    }

    /** Epoch seconds when a freshly issued token dies. */
    public static function freshExpiry(): int
    {
        return time() + self::defaultTtl();
    }

    private static function defaultTtl(): int
    {
        return max(60, (int) config('realtime.token_ttl_seconds', 900));
    }

    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', 'realtime:'.$payload, (string) config('app.key'));
    }
}

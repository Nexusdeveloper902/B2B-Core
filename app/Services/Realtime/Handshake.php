<?php

namespace App\Services\Realtime;

/**
 * RFC 6455 opening-handshake helpers (TASK-016, ADR-026).
 *
 * The realtime server speaks just enough HTTP to accept a WebSocket
 * upgrade: parse the request head, compute Sec-WebSocket-Accept from
 * the client's key, and answer 101 — or refuse with a plain HTTP
 * status (401 for a bad feed token) before any WS framing happens.
 */
final class Handshake
{
    /** The RFC 6455 §1.3 magic GUID. */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Sec-WebSocket-Accept = base64(SHA1(key + GUID)) — RFC 6455 §4.2.2.
     */
    public static function acceptKey(string $secWebSocketKey): string
    {
        return base64_encode(sha1($secWebSocketKey.self::GUID, true));
    }

    /**
     * Parse a raw HTTP request head into lowercased headers + path bits.
     *
     * @return array{request_line: string, method: string, path: string, query: array<string, string>, headers: array<string, string>}
     */
    public static function parseRequest(string $rawHead): array
    {
        $lines = explode("\r\n", rtrim($rawHead, "\r\n"));
        $requestLine = (string) ($lines[0] ?? '');
        $headers = [];

        foreach (\array_slice($lines, 1) as $line) {
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $parts = explode(' ', $requestLine);
        $target = $parts[1] ?? '/';
        [$path, $query] = \array_pad(explode('?', $target, 2), 2, '');

        $queryPairs = [];
        if ($query !== '') {
            parse_str($query, $queryPairs);
        }

        return [
            'request_line' => $requestLine,
            'method' => $parts[0] ?? '',
            'path' => $path,
            'query' => $queryPairs,
            'headers' => $headers,
        ];
    }

    /**
     * Is this head a valid WebSocket upgrade we should answer?
     * (Method GET, Upgrade: websocket, a Sec-WebSocket-Key present.)
     */
    public static function isUpgrade(array $request): bool
    {
        return ($request['method'] ?? '') === 'GET'
            && strcasecmp((string) ($request['headers']['upgrade'] ?? ''), 'websocket') === 0
            && (($request['headers']['sec-websocket-key'] ?? '') !== '');
    }

    public static function successResponse(string $acceptKey): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
    }

    /**
     * Plain-HTTP refusal — used before any WS framing starts (bad or
     * expired token, non-upgrade request).
     */
    public static function denialResponse(int $status, string $reason): string
    {
        $text = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            426 => 'Upgrade Required',
            default => 'Error',
        };

        return "HTTP/1.1 {$status} {$text}\r\n"
            ."Connection: close\r\n"
            .'Content-Type: text/plain; charset=utf-8'."\r\n"
            .'Content-Length: '.\strlen($reason)."\r\n\r\n{$reason}";
    }
}

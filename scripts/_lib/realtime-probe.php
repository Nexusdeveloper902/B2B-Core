<?php

use App\Services\Realtime\WsFrame;

/**
 * scripts/_lib/realtime-probe.php — one-shot WebSocket probe for the
 * realtime feed (TASK-016, ADR-026).
 *
 * Usage:  php realtime-probe.php ws://127.0.0.1:8081 [token]
 * Exit:   0 — handshake accepted (101) AND a valid hello frame arrived
 *         2 — refused with HTTP 401 (bad/expired token — the expected
 *             outcome when probing auth on purpose)
 *         1 — anything else (unreachable, non-WS answer, timeout)
 *
 * Used by scripts/e2e.sh (2 checks) and handy on any bench:
 *   php scripts/_lib/realtime-probe.php ws://127.0.0.1:8081 <token>
 */
$url = $argv[1] ?? '';
$token = $argv[2] ?? null;

$autoloader = __DIR__.'/../../vendor/autoload.php';
if (! is_file($autoloader)) {
    fwrite(STDERR, "vendor/ missing — run: ./run setup / falta vendor/ — ejecuta: ./run setup\n");
    exit(1);
}
require $autoloader;

if (! preg_match('#^ws://([^/:]+):(\d+)$#', $url, $m)) {
    fwrite(STDERR, "usage: php realtime-probe.php ws://host:port [token]\n");
    exit(1);
}
[, $host, $port] = $m;

$key = base64_encode(random_bytes(16));
$path = '/app'.($token !== null ? '?token='.urlencode($token) : '');

$sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2.0);
if ($sock === false) {
    fwrite(STDERR, "connect failed: {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_timeout($sock, 3);

$request = "GET {$path} HTTP/1.1\r\n"
    ."Host: {$host}:{$port}\r\n"
    ."Upgrade: websocket\r\n"
    ."Connection: Upgrade\r\n"
    ."Sec-WebSocket-Key: {$key}\r\n"
    ."Sec-WebSocket-Version: 13\r\n\r\n";
fwrite($sock, $request);

// Read the HTTP head of the response.
$head = '';
$deadline = microtime(true) + 3;
while (($pos = strpos($head, "\r\n\r\n")) === false && microtime(true) < $deadline) {
    $chunk = @fgets($sock, 1024);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $head .= $chunk;
}
$pos = strpos($head, "\r\n\r\n");
if ($pos === false) {
    fwrite(STDERR, "no HTTP response head\n");
    exit(1);
}
$statusLine = strtok($head, "\r\n");
$statusCode = (int) (explode(' ', $statusLine)[1] ?? 0);

if ($statusCode !== 101) {
    if ($statusCode === 401) {
        echo "refused: HTTP 401 (token rejected — expected when probing bad tokens)\n";
        exit(2);
    }
    fwrite(STDERR, "refused: {$statusLine}\n");
    exit(1);
}

// Read frames until the hello (text) frame arrives.
$buffer = substr($head, $pos + 4);
$deadline = microtime(true) + 3;
while (microtime(true) < $deadline) {
    $result = WsFrame::decode($buffer);
    if ($result['frame'] !== null) {
        $frame = $result['frame'];
        if ($frame['opcode'] === WsFrame::OP_TEXT) {
            $hello = json_decode($frame['payload'], true);
            if (is_array($hello) && ($hello['type'] ?? '') === 'hello') {
                $count = is_array($hello['events'] ?? null) ? count($hello['events']) : 0;
                echo 'hello ok: last_id='.(int) ($hello['last_id'] ?? 0)." events={$count}\n";
                fclose($sock);
                exit(0);
            }
        }
        $buffer = substr($buffer, $result['consumed']);
    } else {
        $chunk = @fread($sock, 4096);
        if ($chunk === false || ($chunk === '' && feof($sock))) {
            break;
        }
        $buffer .= (string) $chunk;
    }
}

fwrite(STDERR, "no hello frame within timeout\n");
exit(1);

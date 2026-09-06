<?php

namespace App\Console\Commands;

use App\Services\Realtime\Handshake;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimeToken;
use App\Services\Realtime\WsFrame;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * realtime:serve — the hand-rolled WebSocket feed server (TASK-016,
 * ADR-026).
 *
 * Long-running, zero-dependency (pure stream sockets + stream_select;
 * no pcntl, no event loops, no composer packages). The `events` table
 * is the broadcast source: every poll cycle pushes rows newer than the
 * last pushed id, so the web tier stays 100% decoupled — a tap written
 * by ANY process sharing the database (artisan serve, php -S, a test)
 * shows up on every connected dashboard within one poll.
 *
 * Auth: the HTTP upgrade request must carry a valid feed token
 * (HMAC-SHA256, APP_KEY-signed — see RealtimeToken). Invalid or
 * expired tokens are refused with plain HTTP 401 before any framing.
 *
 * Failure honesty: if the database is unavailable mid-run (a `./run
 * reset` replacing the sqlite file, say), the poll catches the error,
 * reconnects and keeps serving — a quiet feed beats a dead dashboard
 * process.
 */
class RealtimeServeCommand extends Command
{
    protected $signature = 'realtime:serve
        {--host= : Bind host (default: config realtime.host)}
        {--port= : Bind port (default: config realtime.port)}';

    protected $description = 'Serve the real-time dashboard feed over WebSockets (TASK-016)';

    private const SELECT_TIMEOUT_SECONDS = 0;

    private const SELECT_TIMEOUT_MICRO = 250000; // 250 ms — caps event latency even when idle

    /** @var array<int, array{sock: resource, buf: string, handshook: bool}> keyed by (int) socket */
    private array $clients = [];

    private int $lastEventId = 0;

    private float $lastPollAt = 0.0;

    private RealtimeFeed $feed;

    public function handle(RealtimeFeed $feed): int
    {
        $this->feed = $feed;

        $host = (string) ($this->option('host') ?: config('realtime.host'));
        $port = (int) ($this->option('port') ?: config('realtime.port'));
        $pollMs = max(50, (int) config('realtime.poll_ms'));
        $historyLimit = max(1, (int) config('realtime.history_limit'));

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            $this->error("realtime:serve cannot bind {$host}:{$port} — {$errstr} ({$errno}).");
            $this->error('Another realtime server may already own the port / Puede haber otro servidor en el puerto.');

            return self::FAILURE;
        }
        stream_set_blocking($server, false);

        // Start at the current head: fresh connects get HISTORY in their
        // hello frame; only genuinely new taps broadcast.
        $this->lastEventId = $feed->latestEventId();
        $this->lastPollAt = microtime(true);

        $this->info("realtime:serve listening on ws://{$host}:{$port} — poll {$pollMs} ms, history {$historyLimit}, head id {$this->lastEventId}.");

        while (true) {
            $read = [$server];
            foreach ($this->clients as $client) {
                $read[] = $client['sock'];
            }
            $write = $except = null;

            $ready = @stream_select($read, $write, $except, self::SELECT_TIMEOUT_SECONDS, self::SELECT_TIMEOUT_MICRO);
            if ($ready === false) {
                break; // select was interrupted beyond recovery — exit cleanly
            }

            foreach ($read as $socket) {
                if ($socket === $server) {
                    $this->accept($server, $historyLimit);
                } else {
                    $this->read($socket);
                }
            }

            $this->poll($pollMs);
        }

        return self::SUCCESS;
    }

    private function accept($server, int $historyLimit): void
    {
        $new = @stream_socket_accept($server, 0);
        if ($new === false) {
            return;
        }
        stream_set_blocking($new, false);
        $this->clients[(int) $new] = ['sock' => $new, 'buf' => '', 'handshook' => false];
    }

    private function read($socket): void
    {
        $clientId = (int) $socket;
        if (! isset($this->clients[$clientId])) {
            return;
        }

        $data = @fread($socket, 8192);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($clientId);

            return;
        }
        if ($data === '') {
            return; // non-blocking no-op
        }

        $this->clients[$clientId]['buf'] .= $data;

        if (! $this->clients[$clientId]['handshook']) {
            $this->handshake($clientId, $socket);

            return;
        }

        // Drain every complete frame in the buffer.
        while (true) {
            $result = WsFrame::decode($this->clients[$clientId]['buf']);
            if ($result['error'] !== null) {
                $this->drop($clientId);

                return;
            }
            if ($result['frame'] === null) {
                return; // incomplete — wait for more bytes
            }
            $this->clients[$clientId]['buf'] = substr($this->clients[$clientId]['buf'], $result['consumed']);
            $this->handleFrame($clientId, $socket, $result['frame']);
            if (! isset($this->clients[$clientId])) {
                return; // a close frame dropped the client
            }
        }
    }

    /**
     * Complete the HTTP side of the WebSocket upgrade (or refuse it).
     */
    private function handshake(int $clientId, $socket): void
    {
        $buffer = $this->clients[$clientId]['buf'];
        $headEnd = strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            return; // headers not fully buffered yet
        }

        $head = substr($buffer, 0, $headEnd);
        $rest = substr($buffer, $headEnd + 4);
        $request = Handshake::parseRequest($head);

        $token = $request['query']['token'] ?? null;
        $userId = RealtimeToken::verify(\is_string($token) ? $token : null);

        if ($userId === null) {
            @fwrite($socket, Handshake::denialResponse(401, 'Invalid or expired realtime feed token.'));
            $this->drop($clientId);

            return;
        }

        if (! Handshake::isUpgrade($request)) {
            @fwrite($socket, Handshake::denialResponse(426, 'WebSocket upgrade required.'));
            $this->drop($clientId);

            return;
        }

        $accept = Handshake::acceptKey($request['headers']['sec-websocket-key']);
        @fwrite($socket, Handshake::successResponse($accept));

        $this->clients[$clientId]['handshook'] = true;
        $this->clients[$clientId]['buf'] = $rest;

        $this->sendJson($socket, [
            'type' => 'hello',
            'last_id' => $this->lastEventId,
            'events' => $this->feed->recent((int) config('realtime.history_limit')),
        ]);
    }

    /**
     * @param  array{fin: bool, opcode: int, payload: string}  $frame
     */
    private function handleFrame(int $clientId, $socket, array $frame): void
    {
        match ($frame['opcode']) {
            WsFrame::OP_CLOSE => $this->drop($clientId),
            WsFrame::OP_PING => $this->write($clientId, WsFrame::encode($frame['payload'], WsFrame::OP_PONG)),
            WsFrame::OP_PONG, WsFrame::OP_TEXT, WsFrame::OP_BINARY, WsFrame::OP_CONT => null,
            default => $this->drop($clientId), // unknown opcode — protocol violation
        };
    }

    /**
     * Push any events newer than the head to every connected client.
     */
    private function poll(int $pollMs): void
    {
        $interval = $pollMs / 1000.0;
        if ((microtime(true) - $this->lastPollAt) < $interval) {
            return;
        }
        $this->lastPollAt = microtime(true);

        try {
            $rows = $this->feed->eventsAfter($this->lastEventId);
        } catch (QueryException) {
            // The DB file may be mid-replacement (./run reset) or briefly
            // locked: purge the connection, retry on the next tick.
            DB::purge();

            return;
        }

        foreach ($rows as $row) {
            $this->lastEventId = $row['id'];
            $frame = WsFrame::encode(json_encode(['type' => 'tap', 'event' => $row], JSON_UNESCAPED_UNICODE));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook']) {
                    $this->write($clientId, $frame);
                }
            }
        }
    }

    private function sendJson($socket, array $message): void
    {
        $this->write((int) $socket, WsFrame::encode(json_encode($message, JSON_UNESCAPED_UNICODE)));
    }

    private function write(int $clientId, string $frame): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }
        if (@fwrite($this->clients[$clientId]['sock'], $frame) === false) {
            $this->drop($clientId);
        }
    }

    private function drop(int $clientId): void
    {
        if (isset($this->clients[$clientId])) {
            @fclose($this->clients[$clientId]['sock']);
            unset($this->clients[$clientId]);
        }
    }
}

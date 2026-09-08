<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Services\Realtime\Handshake;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimePairing;
use App\Services\Realtime\RealtimeRecycling;
use App\Services\Realtime\RealtimeRoster;
use App\Services\Realtime\RealtimeToken;
use App\Services\Realtime\WsFrame;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * realtime:serve — the hand-rolled WebSocket feed server (TASK-016,
 * ADR-026; pairing channel TASK-020, ADR-029).
 *
 * Long-running, zero-dependency (pure stream sockets + stream_select;
 * no pcntl, no event loops, no composer packages). The `events` table
 * is the broadcast source: every poll cycle pushes rows newer than the
 * last pushed id, so the web tier stays 100% decoupled — a tap written
 * by ANY process sharing the database (artisan serve, php -S, a test)
 * shows up on every connected dashboard within one poll. TASK-020 adds
 * the pairing channel with the same rule: `pending_pairings` row
 * changes (arm / consume / reject) broadcast the shared pairing status
 * payload, so the pairing desk updates the instant a card is paired —
 * no poll cadence, no F5.
 *
 * TASK-025 item 6 adds the recycling channel (spec §24–§29): the
 * append-only `recycling_updates` table is written transactionally by
 * whatever process performs a recycling state change (capture stored,
 * validation started, validated, points awarded, reward redeemed,
 * leaderboard updated); this server polls rows newer than its head and
 * pushes them as `recycling` frames — committed state only, by
 * construction (rows become visible only at commit).
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

    /** @var array<int, array{sock: resource, buf: string, handshook: bool, admin: bool, role: string, class_ids: array<int, int>|null, student_id: int|null}> keyed by (int) socket */
    private array $clients = [];

    private int $lastEventId = 0;

    private float $lastPollAt = 0.0;

    /** TASK-020 — last-seen pairing signature (row-data fingerprint). */
    private string $lastPairingSignature = '';

    /** TASK-025 item 6 — head id of the recycling channel. */
    private int $lastRecyclingId = 0;

    /** TASK-029 — head id of the roster channel. */
    private int $lastRosterId = 0;

    private RealtimeFeed $feed;

    private RealtimePairing $pairing;

    private RealtimeRecycling $recycling;

    private RealtimeRoster $roster;

    public function handle(RealtimeFeed $feed, RealtimePairing $pairing, RealtimeRecycling $recycling, RealtimeRoster $roster): int
    {
        $this->feed = $feed;
        $this->pairing = $pairing;
        $this->recycling = $recycling;
        $this->roster = $roster;

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
        // TASK-020 — same rule for the pairing channel: connect AFTER the
        // current snapshot (hello carries it), broadcast only changes.
        $this->lastPairingSignature = $pairing->signature();
        // TASK-025 — and the recycling channel: hello carries the recent
        // snapshot, then only genuinely new updates broadcast.
        $this->lastRecyclingId = $recycling->latestUpdateId();
        // TASK-029 — and the roster channel: same rule.
        $this->lastRosterId = $roster->latestUpdateId();

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
        $this->clients[(int) $new] = [
            'sock' => $new,
            'buf' => '',
            'handshook' => false,
            'admin' => false,
            // TASK-027 — per-connection tap-channel scope, resolved once
            // at handshake (fail closed). Teachers see only taps of
            // students in their classes; student accounts see only their
            // own taps; admins see the whole school.
            'role' => '',
            'class_ids' => null,
            'student_id' => null,
        ];
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

        // TASK-020 — the pairing channel carries card UIDs (the exact
        // data the admin-only REST status endpoint serves). Tap frames
        // deliberately never do. So pairing frames are delivered to
        // ADMIN connections only: resolve the role ONCE per connection
        // (the token is a user id, not a session), fail closed.
        //
        // TASK-027 — the same lookup also resolves the tap-channel scope
        // (teacher class ids / student account id) for this connection.
        $scope = $this->resolveScope((int) $userId);
        $this->clients[$clientId]['admin'] = $scope['admin'];
        $this->clients[$clientId]['role'] = $scope['role'];
        $this->clients[$clientId]['class_ids'] = $scope['class_ids'];
        $this->clients[$clientId]['student_id'] = $scope['student_id'];

        $accept = Handshake::acceptKey($request['headers']['sec-websocket-key']);
        @fwrite($socket, Handshake::successResponse($accept));

        $this->clients[$clientId]['handshook'] = true;
        $this->clients[$clientId]['buf'] = $rest;

        $hello = [
            'type' => 'hello',
            'last_id' => $this->lastEventId,
            // TASK-027 — the hello snapshot honors the same per-connection
            // scope as live tap frames (a teacher's history is their own
            // classes' history, not the school's).
            'events' => \array_values(\array_filter(
                $this->feed->recent((int) config('realtime.history_limit')),
                fn (array $row): bool => $this->clientSeesRow($this->clients[$clientId], $row),
            )),
            // TASK-025 — the recycling channel's initial snapshot rides the
            // same hello (student names + points, same exposure level as
            // tap frames — no card UIDs, so every role may receive it).
            'recycling' => $this->recycling->recent((int) config('realtime.history_limit')),
        ];
        if ($this->clients[$clientId]['admin']) {
            // TASK-020 — the pairing desk's initial state rides the same
            // hello (SSR already painted it; this reconciles the gap) —
            // admins only (card UIDs cross this wire, same as the REST
            // status endpoint the payload mirrors).
            $hello['pairing'] = $this->pairing->payload();
            // TASK-029 — the roster channel's recent snapshot too: a
            // freshly connected admin page replays it through the same
            // idempotent handlers live frames use, reconciling anything
            // that changed between its SSR paint and this connect.
            $hello['roster'] = $this->roster->recent(30);
        }
        $this->sendJson($socket, $hello);
    }

    /** One role/scope lookup per connection; fail closed on any doubt. */
    private function isAdmin(int $userId): bool
    {
        try {
            return DB::table('users')->where('id', $userId)->value('role') === UserRole::Admin->value;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * TASK-027 — resolve the tap-channel scope for one connection.
     *
     * @return array{admin: bool, role: string, class_ids: array<int, int>|null, student_id: int|null}
     */
    private function resolveScope(int $userId): array
    {
        $scope = ['admin' => false, 'role' => '', 'class_ids' => null, 'student_id' => null];

        try {
            $user = DB::table('users')->where('id', $userId)->first(['id', 'role', 'student_id']);
        } catch (\Throwable) {
            return $scope; // fail closed — an unreadable user serves nothing
        }

        if ($user === null) {
            return $scope;
        }

        $scope['role'] = (string) $user->role;

        if ($user->role === UserRole::Admin->value) {
            $scope['admin'] = true;

            return $scope; // school-wide
        }

        if ($user->role === UserRole::Student->value) {
            $scope['student_id'] = $user->student_id !== null ? (int) $user->student_id : null;

            return $scope;
        }

        if ($user->role === UserRole::Teacher->value) {
            try {
                $scope['class_ids'] = DB::table('classes')
                    ->where('teacher_user_id', $userId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable) {
                $scope['class_ids'] = []; // fail closed — no classes resolvable
            }
        }

        return $scope;
    }

    /** TASK-027 — does this connection's scope cover this feed row? */
    private function clientSeesRow(array $client, array $row): bool
    {
        if ($client['student_id'] !== null) {
            return (int) $row['student_id'] === (int) $client['student_id'];
        }

        if ($client['class_ids'] !== null) {
            return $row['class_id'] !== null && in_array((int) $row['class_id'], $client['class_ids'], true);
        }

        return true; // admin / unrestricted
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
     * Push any events newer than the head to every connected client,
     * any pairing-state change as a `pairing` frame (TASK-020), and any
     * recycling updates newer than the head as `recycling` frames
     * (TASK-025 item 6 — committed state only, by construction).
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
                // TASK-027 — the tap channel honors the per-connection
                // scope resolved at handshake: teachers get only their
                // classes' taps, student accounts only their own.
                if ($client['handshook'] && $this->clientSeesRow($client, $row)) {
                    $this->write($clientId, $frame);
                }
            }
        }

        // TASK-020 — the pairing channel: same poll beat, one signature
        // query. Arm / consume / reject mutate pending_pairings; the
        // signature changes; every connected desk gets the fresh state
        // (same payload as GET /api/v1/admin/pairing/status). Time-only
        // transitions (countdown, expiry) are deliberately NOT row
        // changes — the desk's own countdown owns those (ADR-029).
        try {
            $signature = $this->pairing->signature();
        } catch (QueryException) {
            DB::purge();

            return;
        }

        if ($signature !== $this->lastPairingSignature) {
            $this->lastPairingSignature = $signature;
            $frame = WsFrame::encode(json_encode(
                ['type' => 'pairing'] + $this->pairing->payload(),
                JSON_UNESCAPED_UNICODE,
            ));
            foreach ($this->clients as $clientId => $client) {
                // Admin connections only — pairing frames carry card
                // UIDs (see handshake()); teacher dashboards never see
                // them, exactly like the REST status endpoint.
                if ($client['handshook'] && $client['admin']) {
                    $this->write($clientId, $frame);
                }
            }
        }

        // TASK-025 — the recycling channel: same poll beat, same rule as
        // the tap feed — rows newer than the head, oldest first, to
        // every authenticated connection (payloads carry student names
        // and points — the same exposure level as the deliberately
        // school-wide recycling leaderboard, spec §22; no card UIDs).
        // Reads ONLY: the writers were the transactions.
        try {
            $updates = $this->recycling->updatesAfter($this->lastRecyclingId);
        } catch (QueryException) {
            DB::purge();

            return;
        }

        foreach ($updates as $update) {
            $this->lastRecyclingId = $update['id'];
            $frame = WsFrame::encode(json_encode(
                ['type' => 'recycling', 'update' => $update],
                JSON_UNESCAPED_UNICODE,
            ));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook']) {
                    $this->write($clientId, $frame);
                }
            }
        }

        // TASK-029 — the roster channel: rows newer than the head, oldest
        // first, to ADMIN connections only (roster management payloads
        // mirror the admin REST endpoints' exposure — same wire discipline
        // as pairing frames). Reads ONLY: the writers were the transactions.
        try {
            $rosterUpdates = $this->roster->updatesAfter($this->lastRosterId);
        } catch (QueryException) {
            DB::purge();

            return;
        }

        foreach ($rosterUpdates as $update) {
            $this->lastRosterId = $update['id'];
            $frame = WsFrame::encode(json_encode(
                ['type' => 'roster', 'update' => $update],
                JSON_UNESCAPED_UNICODE,
            ));
            foreach ($this->clients as $clientId => $client) {
                if ($client['handshook'] && $client['admin']) {
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

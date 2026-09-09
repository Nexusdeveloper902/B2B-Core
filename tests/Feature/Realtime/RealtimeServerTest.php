<?php

namespace Tests\Feature\Realtime;

use App\Services\Realtime\RealtimeToken;
use App\Services\Realtime\WsFrame;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RealtimeServerTest — the REAL server process over REAL sockets
 * (TASK-016, ADR-026).
 *
 * Boots `php artisan realtime:serve` as a child process against a
 * dedicated file database, then drives the actual wire protocol with
 * a hand-rolled client: HTTP upgrade → 101 → hello (history) → live
 * broadcast after another process writes a tap row — exactly how the
 * web tier produces taps. The tap API itself is already pinned by
 * TapEventTest; here we pin "rows in → frames out".
 */
class RealtimeServerTest extends TestCase
{
    private const APP_KEY = 'realtime-server-test-key';

    /** @var array{proc: resource, pipes: array<int, resource>, port: int, db: string}|null */
    private ?array $server = null;

    protected function tearDown(): void
    {
        $this->stopServer();
        parent::tearDown();
    }

    #[Test]
    public function the_feed_answers_hello_with_history_and_broadcasts_new_taps(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $adminId = $this->seedUser($db, 'admin');
        $seededId = $this->seedTap($db, 'CLASS_ATTENDANCE', '07:50', 'LIVE TEST One');

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $token = RealtimeToken::issue($adminId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no hello frame arrived');
        $this->assertSame('hello', $hello['type']);
        $this->assertSame($seededId, $hello['last_id']);
        $this->assertContains('LIVE TEST One', array_column($hello['events'], 'student_name'));
        // TASK-020 — the hello carries the pairing channel's snapshot too
        // (admins only: the payload carries card UIDs, same as the REST
        // status endpoint; teachers get a plain hello).
        $this->assertArrayHasKey('pairing', $hello);
        $this->assertNull($hello['pairing']['pending']);

        // A tap written by ANOTHER process (the web tier's job) must
        // broadcast to the connected dashboard within a few polls.
        $newId = $this->seedTap($db, 'PAE_LUNCH', '12:02', 'LIVE TEST Two');

        $tap = $this->readMessage($sock);
        $this->assertNotNull($tap, 'no tap frame arrived after a new event row');
        $this->assertSame('tap', $tap['type']);
        $this->assertSame($newId, $tap['event']['id']);
        $this->assertSame('LIVE TEST Two', $tap['event']['student_name']);
        $this->assertSame('PAE_LUNCH', $tap['event']['type']);
        $this->assertSame('12:02', $tap['event']['time']);
        $this->assertSame('Live Reader', $tap['event']['reader_label']);
        $this->assertIsInt($tap['event']['student_id']);

        fclose($sock);
    }

    #[Test]
    public function pairing_state_changes_broadcast_to_connected_desks(): void
    {
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $adminId = $this->seedUser($db, 'admin');
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $token = RealtimeToken::issue($adminId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no hello frame arrived');
        $this->assertArrayHasKey('pairing', $hello);

        // A pending pairing written by ANOTHER process (the desk's arm
        // endpoint's job) must broadcast as a `pairing` frame within a
        // few polls — this is the "desk updates the instant a card is
        // paired" channel (TASK-020, ADR-029).
        $this->seedPendingPairing($db, 'LIVE PAIR Student');

        $armed = $this->readMessage($sock);
        $this->assertNotNull($armed, 'no pairing frame arrived after an arm');
        $this->assertSame('pairing', $armed['type']);
        $this->assertSame('LIVE PAIR Student', $armed['pending']['student_name']);
        $this->assertIsInt($armed['pending']['seconds_left']);
        $this->assertGreaterThan(0, $armed['pending']['seconds_left']);

        // Consuming the window (the reader's pair endpoint's job) is a
        // row change too: the frame flips to the completed state.
        $conn = DB::connection('realtime_file');
        $conn->table('pending_pairings')->update([
            'card_id' => $this->seedPairCard($conn, 'LIVEPAIRCARD'),
            'consumed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $done = $this->readMessage($sock);
        $this->assertNotNull($done, 'no pairing frame arrived after a consume');
        $this->assertSame('pairing', $done['type']);
        $this->assertNull($done['pending']);
        $this->assertSame('LIVEPAIRCARD', $done['last_pairing']['card_uid']);
        $this->assertSame('LIVE PAIR Student', $done['last_pairing']['student_name']);

        fclose($sock);
    }

    #[Test]
    public function pairing_frames_never_reach_teacher_connections(): void
    {
        // TASK-020 — the privacy floor: pairing frames carry card UIDs,
        // so they are admin-only. A teacher's feed connection still gets
        // the tap channel (hello + taps) but NEVER a pairing frame —
        // same data plane the admin-only REST status endpoint enforces.
        // TASK-027 — the tap channel itself is scope-fenced now: the tap
        // below is seeded on a student IN the teacher's class, so it
        // arrives (out-of-scope silence is pinned by the dedicated test).
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $classId = $this->seedClass($db, $teacherId);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $token = RealtimeToken::issue($teacherId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no hello frame arrived');
        $this->assertArrayNotHasKey('pairing', $hello, 'teacher hellos carry no pairing snapshot');

        // A pairing is armed and consumed while the teacher watches: no
        // pairing frame may arrive (budget generous vs the 100 ms poll).
        $this->seedPendingPairing($db, 'SECRET PAIR Student');
        usleep(400000);

        $silence = $this->readMessageIfAny($sock, 0.6);
        $this->assertNull($silence, 'a teacher connection must not receive pairing frames');

        // But the tap channel is open for the same connection: a tap on a
        // student of the teacher's own class still arrives.
        $this->seedTap($db, 'CLASS_ATTENDANCE', '07:55', 'LIVE TEST Teacher', $classId);
        $tap = $this->readMessage($sock);
        $this->assertNotNull($tap, 'tap frames must still reach teachers (for their own classes)');
        $this->assertSame('tap', $tap['type']);

        fclose($sock);
    }

    #[Test]
    public function teacher_connections_only_receive_taps_of_their_own_classes(): void
    {
        // TASK-027 — the teacher data wall on the realtime channel: a tap
        // on a student OUTSIDE the teacher's classes never crosses their
        // wire (an admin connection on the same server still receives
        // it — the school-wide plane is untouched).
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $adminId = $this->seedUser($db, 'admin');
        $classId = $this->seedClass($db, $teacherId);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $teacherToken = RealtimeToken::issue($teacherId, time() + 120);
        [$teacherSock] = $this->upgrade($port, $teacherToken);
        $this->readMessage($teacherSock); // drain hello

        $adminToken = RealtimeToken::issue($adminId, time() + 120);
        [$adminSock] = $this->upgrade($port, $adminToken);
        $this->readMessage($adminSock); // drain hello

        // Out-of-scope tap: student with no class at all.
        $outsideId = $this->seedTap($db, 'CLASS_ATTENDANCE', '07:50', 'OUT OF SCOPE Student', null);

        $adminTap = $this->readMessage($adminSock);
        $this->assertNotNull($adminTap, 'the admin still receives the school-wide tap');
        $this->assertSame('tap', $adminTap['type']);
        $this->assertSame($outsideId, $adminTap['event']['id']);

        usleep(400000);
        $teacherSilence = $this->readMessageIfAny($teacherSock, 0.6);
        $this->assertNull($teacherSilence, 'a teacher must not receive taps of students outside their classes');

        // In-scope tap: same teacher's class — arrives on both planes.
        $insideId = $this->seedTap($db, 'CLASS_ATTENDANCE', '07:55', 'IN SCOPE Student', $classId);

        $teacherTap = $this->readMessage($teacherSock);
        $this->assertNotNull($teacherTap, 'the in-scope tap must reach the teacher');
        $this->assertSame($insideId, $teacherTap['event']['id']);

        $adminTap2 = $this->readMessage($adminSock);
        $this->assertNotNull($adminTap2, 'the in-scope tap also reaches the admin');

        fclose($teacherSock);
        fclose($adminSock);
    }

    #[Test]
    public function student_connections_only_receive_their_own_taps(): void
    {
        // TASK-027 — a student account's realtime connection is scoped to
        // their own student row: other students' taps never cross the
        // wire, their own taps do (the balance/self-service plane).
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();

        // The student account needs its student_id link (1:1 account layer).
        $conn = DB::connection('realtime_file');
        $ownStudentId = $conn->table('students')->insertGetId([
            'name' => 'OWN STUDENT',
            'grade' => '3°',
            'pae_enrolled' => 0,
            'class_id' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $studentUserId = $this->seedUser($db, 'student', $ownStudentId);

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $token = RealtimeToken::issue($studentUserId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no hello frame arrived');

        // Someone else's tap: never delivered to the student connection.
        $this->seedTap($db, 'CLASS_ATTENDANCE', '07:50', 'SOMEONE ELSE', null);
        usleep(400000);

        $silence = $this->readMessageIfAny($sock, 0.6);
        $this->assertNull($silence, 'a student connection must not receive other students\' taps');

        // Their own tap: delivered.
        $readerId = (int) $conn->table('readers')->where('label', 'Live Reader')->value('id');
        $ownCardId = $conn->table('cards')->insertGetId([
            'credential_uid' => 'OWN'.strtoupper(bin2hex(random_bytes(4))),
            'student_id' => $ownStudentId,
            'status' => 'active',
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $ownEventId = (int) $conn->table('events')->insertGetId([
            'card_id' => $ownCardId,
            'reader_id' => $readerId,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->toDateString().' 07:56:00',
            'metadata' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $ownTap = $this->readMessage($sock);
        $this->assertNotNull($ownTap, 'the student\'s own tap must be delivered');
        $this->assertSame($ownEventId, $ownTap['event']['id']);
        $this->assertSame('OWN STUDENT', $ownTap['event']['student_name']);

        fclose($sock);
    }

    #[Test]
    public function invalid_tokens_are_refused_with_plain_http_401_before_any_framing(): void
    {
        $db = storage_path('framework/testing/realtime-bogus-'.uniqid().'.sqlite');

        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        [$sock, $head] = $this->upgrade($port, 'totally-bogus-token');

        // The head terminator stops fgets; the body rides the same TCP
        // segment and comes back from the stream buffer.
        $body = (string) @fread($sock, 256);

        $this->assertStringStartsWith('HTTP/1.1 401', $head);
        $this->assertStringContainsString('Connection: close', $head);
        $this->assertStringContainsString('realtime feed token', $head.$body);

        fclose($sock);
    }

    #[Test]
    public function recycling_updates_broadcast_to_every_connection_and_ride_the_hello(): void
    {
        // TASK-025 item 6 — rows in recycling_updates written by ANOTHER
        // process (the award transaction's job) broadcast as `recycling`
        // frames to every authenticated connection, and the hello frame
        // carries the channel's recent snapshot.
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $teacherId = $this->seedUser($db, 'teacher');
        $seededId = $this->seedRecyclingUpdate($db, 'points_awarded', ['student_id' => 7, 'points' => 10, 'new_balance' => 10]);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $token = RealtimeToken::issue($teacherId, time() + 120);
        [$sock] = $this->upgrade($port, $token);

        $hello = $this->readMessage($sock);
        $this->assertNotNull($hello, 'no hello frame arrived');
        $this->assertSame('hello', $hello['type']);
        $this->assertArrayHasKey('recycling', $hello, 'the hello carries the recycling channel snapshot');
        $this->assertSame('points_awarded', $hello['recycling'][0]['type']);
        $this->assertSame(10, $hello['recycling'][0]['payload']['points']);

        // A committed recycling update (the award transaction's write)
        // broadcasts — to EVERY role (no card UIDs in payloads).
        $newId = $this->seedRecyclingUpdate($db, 'reward_redeemed', ['student_id' => 7, 'points_spent' => 5]);

        $frame = $this->readMessage($sock);
        $this->assertNotNull($frame, 'no recycling frame arrived after a committed update');
        $this->assertSame('recycling', $frame['type']);
        $this->assertSame($newId, $frame['update']['id']);
        $this->assertSame('reward_redeemed', $frame['update']['type']);
        $this->assertSame(5, $frame['update']['payload']['points_spent']);

        fclose($sock);
    }

    #[Test]
    public function roster_updates_broadcast_to_admins_only_and_ride_the_hello(): void
    {
        // TASK-029 — rows in roster_updates written by ANOTHER process
        // (the admin API transactions' job) broadcast as `roster` frames
        // to ADMIN connections only, and the admin hello carries the
        // channel's recent snapshot (teachers get neither).
        config(['app.key' => self::APP_KEY]);

        $db = $this->freshFileDatabase();
        $adminId = $this->seedUser($db, 'admin');
        $teacherId = $this->seedUser($db, 'teacher');
        $seededId = $this->seedRosterUpdate($db, 'student_created', ['id' => 9, 'name' => 'Roster Live One', 'grade' => '2°', 'class_name' => '2° A', 'pae_enrolled' => false]);
        $port = $this->startServer($db);
        $this->assertNotNull($port, 'the realtime server failed to boot');

        $adminToken = RealtimeToken::issue($adminId, time() + 120);
        [$adminSock] = $this->upgrade($port, $adminToken);

        $hello = $this->readMessage($adminSock);
        $this->assertNotNull($hello, 'no hello frame arrived');
        $this->assertSame('hello', $hello['type']);
        $this->assertArrayHasKey('roster', $hello, 'the admin hello carries the roster channel snapshot');
        $this->assertSame('student_created', $hello['roster'][0]['type']);
        $this->assertSame('Roster Live One', $hello['roster'][0]['payload']['name']);

        $teacherToken = RealtimeToken::issue($teacherId, time() + 120);
        [$teacherSock] = $this->upgrade($port, $teacherToken);
        $teacherHello = $this->readMessage($teacherSock);
        $this->assertArrayNotHasKey('roster', $teacherHello, 'teachers never receive the roster channel (admin-only frames)');

        // A committed roster update (the API transaction's write)
        // broadcasts to admins only.
        $newId = $this->seedRosterUpdate($db, 'class_created', ['id' => 4, 'name' => '7° B']);

        $frame = $this->readMessage($adminSock);
        $this->assertNotNull($frame, 'no roster frame arrived for the admin after a committed update');
        $this->assertSame('roster', $frame['type']);
        $this->assertSame($newId, $frame['update']['id']);
        $this->assertSame('class_created', $frame['update']['type']);
        $this->assertSame('7° B', $frame['update']['payload']['name']);

        // The teacher connection gets NOTHING for the roster write (its
        // socket stays silent — the next read times out empty).
        $this->assertNull($this->readMessageIfAny($teacherSock, 0.2), 'a roster frame leaked to a teacher connection');

        fclose($adminSock);
        fclose($teacherSock);
    }

    // ------------------------------------------------------------------ helpers

    private function freshFileDatabase(): string
    {
        $db = storage_path('framework/testing/realtime-'.uniqid().'.sqlite');
        @mkdir(dirname($db), 0777, true);
        touch($db);

        config(['database.connections.realtime_file' => [
            'driver' => 'sqlite',
            'database' => $db,
            'prefix' => '',
        ]]);

        $this->artisan('migrate', ['--database' => 'realtime_file', '--force' => true]);

        return $db;
    }

    /** Insert one complete tap (reader + student + card + event) and return the event id. */
    private function seedTap(string $db, string $type, string $time, string $studentName, ?int $classId = null): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        $readerId = $conn->table('readers')->where('label', 'Live Reader')->value('id');
        if ($readerId === null) {
            $readerId = $conn->table('readers')->insertGetId([
                'label' => 'Live Reader',
                'type' => 'classroom',
                'active_event_type' => $type,
                'api_key' => 'realtime-test-key-'.uniqid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $studentId = $conn->table('students')->insertGetId([
            'name' => $studentName,
            'grade' => '3°',
            'pae_enrolled' => 0,
            'class_id' => $classId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $cardId = $conn->table('cards')->insertGetId([
            'credential_uid' => 'LIVE'.strtoupper(bin2hex(random_bytes(4))),
            'student_id' => $studentId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $conn->table('events')->insertGetId([
            'card_id' => $cardId,
            'reader_id' => $readerId,
            'type' => $type,
            'occurred_at' => now()->toDateString()." {$time}:00",
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one users row with the given role; returns the id. */
    private function seedUser(string $db, string $role, ?int $studentId = null): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('users')->insertGetId([
            'name' => 'Realtime '.ucfirst($role),
            'email' => 'realtime-'.uniqid().'@presence.test',
            'email_verified_at' => null,
            'password' => 'irrelevant',
            'remember_token' => null,
            'role' => $role,
            'student_id' => $studentId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one class owned by the given teacher; returns the id. */
    private function seedClass(string $db, int $teacherUserId): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('classes')->insertGetId([
            'name' => 'Scope Test '.uniqid(),
            'teacher_user_id' => $teacherUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one recycling_updates row (an award transaction's write); returns the id. */
    private function seedRecyclingUpdate(string $db, string $type, array $payload): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('recycling_updates')->insertGetId([
            'type' => $type,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one roster_updates row (an admin API transaction's write); returns the id. */
    private function seedRosterUpdate(string $db, string $type, array $payload): int
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('roster_updates')->insertGetId([
            'type' => $type,
            'payload' => json_encode($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one armed pending pairing (the arm endpoint's write). */
    private function seedPendingPairing(string $db, string $studentName): void
    {
        $conn = DB::connection('realtime_file');
        $now = now()->format('Y-m-d H:i:s');

        $studentId = $conn->table('students')->insertGetId([
            'name' => $studentName,
            'grade' => '3°',
            'pae_enrolled' => 0,
            'class_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conn->table('pending_pairings')->insert([
            'student_id' => $studentId,
            'reader_id' => null,
            'card_id' => null,
            'expires_at' => now()->addSeconds((int) config('presence.pairing_window_seconds'))->format('Y-m-d H:i:s'),
            'consumed_at' => null,
            'last_rejected_uid' => null,
            'last_rejected_reason' => null,
            'last_rejected_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Insert one cards row (the pair endpoint's write) and return its id. */
    private function seedPairCard($conn, string $uid): int
    {
        $now = now()->format('Y-m-d H:i:s');

        return (int) $conn->table('cards')->insertGetId([
            'credential_uid' => $uid,
            'student_id' => $conn->table('students')->where('name', 'LIVE PAIR Student')->value('id'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Boot the server process on a free port; null when it never listens. */
    private function startServer(string $db): ?int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = random_int(21000, 39000);

            // Inherit the phpunit env (PATH, SystemRoot on Windows, test
            // overrides) and point the app at the dedicated file DB.
            $env = array_merge(getenv() ?: [], [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'APP_KEY' => self::APP_KEY,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $db,
                'REALTIME_POLL_MS' => '100',
            ]);

            $pipes = [];
            $proc = proc_open(
                [PHP_BINARY, 'artisan', 'realtime:serve', '--host=127.0.0.1', '--port='.(int) $port],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $env,
            );
            if (! is_resource($proc)) {
                continue;
            }
            fclose($pipes[0]);

            for ($i = 0; $i < 60; $i++) {
                $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($probe !== false) {
                    fclose($probe);
                    $this->server = ['proc' => $proc, 'pipes' => $pipes, 'port' => $port, 'db' => $db];

                    return $port;
                }
                $status = proc_get_status($proc);
                if (! $status['running']) {
                    break; // died (port clash?) — diag below, then retry
                }
                usleep(100000);
            }

            $diagnostics = '';
            foreach (array_slice($pipes, 1) as $pipe) {
                $diagnostics .= stream_get_contents($pipe);
            }
            @fwrite(STDERR, "realtime:serve boot attempt failed on port {$port}: {$diagnostics}\n");
            proc_close($proc);
        }

        return null;
    }

    private function stopServer(): void
    {
        if ($this->server === null) {
            return;
        }
        proc_terminate($this->server['proc']);
        usleep(100000);
        proc_close($this->server['proc']);
        @unlink($this->server['db']);
        $this->server = null;
    }

    /** Perform the HTTP upgrade; returns [socket, response head]. */
    private function upgrade(int $port, string $token): array
    {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "connect failed: {$errstr} ({$errno})");
        stream_set_timeout($sock, 5);

        $key = base64_encode(random_bytes(16));
        fwrite($sock,
            'GET /app?token='.urlencode($token)." HTTP/1.1\r\n"
            ."Host: 127.0.0.1:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n"
        );

        $head = '';
        while (strpos($head, "\r\n\r\n") === false) {
            $line = fgets($sock, 256);
            if ($line === false) {
                break;
            }
            $head .= $line;
        }

        return [$sock, $head];
    }

    /** Read WS text frames until a JSON message arrives (5 s budget). */
    private function readMessage($sock): ?array
    {
        return $this->readMessageIfAny($sock, 5.0);
    }

    /** Read one JSON message within $budget seconds; null when quiet. */
    private function readMessageIfAny($sock, float $budget): ?array
    {
        $buffer = '';
        $deadline = microtime(true) + $budget;

        while (microtime(true) < $deadline) {
            $result = WsFrame::decode($buffer);
            if ($result['frame'] !== null) {
                if ($result['frame']['opcode'] === WsFrame::OP_TEXT) {
                    $message = json_decode($result['frame']['payload'], true);

                    return is_array($message) ? $message : null;
                }
                $buffer = substr($buffer, $result['consumed']);

                continue;
            }
            if ($result['error'] !== null) {
                return null;
            }

            $chunk = @fread($sock, 8192);
            if ($chunk === false || ($chunk === '' && feof($sock))) {
                return null;
            }
            $buffer .= $chunk;
        }

        return null;
    }
}

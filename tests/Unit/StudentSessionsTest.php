<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix C) — ENTRY/EXIT on the event spine: every entry is
 * registered (multiple rows per day are the POINT), and
 * studentSessions() pairs them on the fly into per-day sessions with
 * time-in-school. Unmatched entries stay open; stray exits pair with
 * nothing; days never leak into each other.
 */
class StudentSessionsTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    private Card $card;

    private Reader $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00'));
        $this->service = new AttendanceService;
        $this->seedDemo();

        $student = Student::where('name', 'Maria González')->firstOrFail();
        $this->card = $student->cards()->firstOrFail();
        $this->gate = Reader::create([
            'label' => 'Session Test Gate',
            'type' => 'entry',
            'active_event_type' => 'ENTRY',
            'api_key' => 'session-test-key-00000000000001',
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function tap(string $type, string $at): PresenceEvent
    {
        return PresenceEvent::create([
            'card_id' => $this->card->id,
            'reader_id' => $this->gate->id,
            'type' => $type,
            'occurred_at' => Carbon::parse($at),
        ]);
    }

    private function sessions(): array
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        return $this->service->studentSessions($student, 2);
    }

    #[Test]
    public function an_entry_exit_pair_makes_one_closed_session(): void
    {
        $this->tap('ENTRY', '2026-09-01 07:05:00');
        $this->tap('EXIT', '2026-09-01 15:00:00');

        $days = $this->sessions();

        $this->assertCount(1, $days);
        $this->assertSame('2026-09-01', $days[0]['date']);
        $this->assertSame(1, $days[0]['entries']);
        $this->assertSame(1, $days[0]['exits']);
        $this->assertSame(475, $days[0]['minutes_in_school']);
        $this->assertFalse($days[0]['open_session']);
    }

    #[Test]
    public function an_unmatched_entry_stays_open_with_zero_minutes(): void
    {
        $this->tap('ENTRY', '2026-09-01 07:05:00');

        $days = $this->sessions();

        $this->assertCount(1, $days);
        $this->assertSame(0, $days[0]['minutes_in_school']);
        $this->assertTrue($days[0]['open_session']);
    }

    #[Test]
    public function multiple_entries_register_and_pair_in_order(): void
    {
        // In, out, back in (forgotten bag), still inside: the history
        // keeps all three entries; the first pair closes, the second
        // stays open.
        $this->tap('ENTRY', '2026-09-01 07:00:00');
        $this->tap('EXIT', '2026-09-01 12:00:00');
        $this->tap('ENTRY', '2026-09-01 13:00:00');

        $days = $this->sessions();

        $this->assertCount(1, $days);
        $this->assertSame(2, $days[0]['entries']);
        $this->assertSame(1, $days[0]['exits']);
        $this->assertSame(300, $days[0]['minutes_in_school']);
        $this->assertTrue($days[0]['open_session']);
        $this->assertCount(2, $days[0]['sessions']);
    }

    #[Test]
    public function a_stray_exit_pairs_with_nothing(): void
    {
        $this->tap('EXIT', '2026-09-01 15:00:00');

        $days = $this->sessions();

        $this->assertCount(1, $days);
        $this->assertSame(0, $days[0]['entries']);
        $this->assertSame(1, $days[0]['exits']);
        $this->assertSame(0, $days[0]['minutes_in_school']);
        $this->assertFalse($days[0]['open_session']);
    }

    #[Test]
    public function days_never_leak_into_each_other(): void
    {
        // Yesterday's open entry must not pair with today's exit.
        $this->tap('ENTRY', '2026-08-31 07:00:00');
        $this->tap('EXIT', '2026-09-01 15:00:00');

        $days = $this->sessions();

        $this->assertCount(2, $days);
        $this->assertSame('2026-09-01', $days[0]['date']);
        $this->assertSame(0, $days[0]['minutes_in_school']);
        $this->assertSame('2026-08-31', $days[1]['date']);
        $this->assertTrue($days[1]['open_session']);
    }

    #[Test]
    public function a_quiet_student_has_no_session_rows(): void
    {
        $quiet = Student::where('name', 'Ana Martínez')->firstOrFail();

        $this->assertSame([], $this->service->studentSessions($quiet, 2));
    }
}

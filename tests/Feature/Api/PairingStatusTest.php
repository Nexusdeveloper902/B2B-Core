<?php

namespace Tests\Feature\Api;

use App\Models\PendingPairing;
use App\Models\Student;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-011 — the read-only pairing status endpoint backing the dashboard
 * pairing desk (GET /api/v1/admin/pairing/status). Covers: the empty
 * state, the armed state (pending + countdown), the completed state
 * (last pairing + history, with the card_id audit trail stamped by the
 * pair step), and the auth surface (guest 401, teacher 403, admin ok).
 *
 * TASK-014 — the armed window also carries last_rejection: a rejected
 * tap (422 already_paired) is stamped on the row and reported here so
 * the desk can SHOW why pairing is not completing instead of counting
 * down in silence and then claiming "window expired".
 */
class PairingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function getStatus(?array $credentials = null): TestResponse
    {
        return $this->getJson('/api/v1/admin/pairing/status', $credentials ?? []);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@presence.test')->firstOrFail();
    }

    #[Test]
    public function guests_are_rejected(): void
    {
        $this->getStatus()->assertUnauthorized();
    }

    #[Test]
    public function teachers_are_forbidden(): void
    {
        $this->actingAs($this->teacher())->getStatus()->assertForbidden();
    }

    #[Test]
    public function an_admin_sees_the_empty_state_on_a_fresh_seed(): void
    {
        // Seeded cards were fabricated by the seeder, not paired: no
        // completed pending_pairings exist, so history is empty.
        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing', null)
            ->assertJsonPath('recent_pairings', []);
    }

    #[Test]
    public function an_armed_session_reports_the_student_and_the_countdown(): void
    {
        $window = (int) config('presence.pairing_window_seconds');
        $student = Student::orderBy('id')->first();
        $pairing = $this->pairings()->arm($student);

        $response = $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending.student_id', $student->id)
            ->assertJsonPath('pending.student_name', $student->name)
            ->assertJsonStructure([
                'status',
                'pending' => ['student_id', 'student_name', 'expires_at', 'seconds_left', 'last_rejection'],
                'last_pairing',
                'recent_pairings',
            ]);

        // A cleanly armed window reports NO rejection (TASK-014 field).
        $this->assertNull($response->json('pending.last_rejection'));

        // Countdown: any value in (0, window] — never a hard-coded instant
        // (a 1 s tick between arm and read would flake an exact match).
        $seconds = $response->json('pending.seconds_left');
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual($window, $seconds);

        $this->assertSame($pairing->id, PendingPairing::where('student_id', $student->id)->latest('id')->first()->id);
    }

    #[Test]
    public function an_expired_session_is_not_pending(): void
    {
        $student = Student::orderBy('id')->first();
        $this->pairings()->arm($student);

        PendingPairing::latest('id')->first()->update([
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);
    }

    #[Test]
    public function arming_supersedes_any_previous_active_window(): void
    {
        // TASK-020 — the bench race: an impatient double-arm (or a re-arm
        // from another tab) used to leave the superseded row live; when
        // the newer row was consumed by the card tap, that zombie
        // resurfaced as "the" armed window and the desk NEVER showed the
        // pairing that had just succeeded. One armed window, always.
        $first = Student::orderBy('id')->first();
        $second = Student::orderBy('id')->skip(1)->first();
        $pairings = $this->pairings();

        $older = $pairings->arm($first);
        $newer = $pairings->arm($second);

        // The new arm is THE window; the older row is closed, not dormant.
        $this->assertTrue($newer->fresh()->isActive());
        $this->assertFalse($older->fresh()->isActive(), 'arming must close the previous window');

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending.student_id', $second->id);

        // And the operator's exact bench sequence: card tap consumes the
        // live window -> the desk surfaces the SUCCESS, not a zombie.
        $this->pairings()->pair($this->reader('classroom'), 'SUPERSeded001');

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing.card_uid', 'SUPERSeded001')
            ->assertJsonPath('last_pairing.student_name', $second->name);
    }

    #[Test]
    public function a_window_written_by_a_clock_the_machine_no_longer_has_is_not_pending(): void
    {
        // TASK-020 — the "Armed for Maria — 12468 s left" case: a row
        // written while the system clock ran ahead (NTP correction, VM
        // resume, dual-boot RTC) has created_at in the "future" once the
        // clock is corrected. Its far-future expires_at was computed
        // against a clock the machine has abandoned — stale by
        // definition: not pending at the desk, not pairable at the reader.
        $student = Student::orderBy('id')->first();
        $this->pairings()->arm($student);

        PendingPairing::latest('id')->first()->forceFill([
            'created_at' => now()->addSeconds(12600),
            'updated_at' => now(),
            'expires_at' => now()->addSeconds(12645),
        ])->save();

        $this->actingAs($this->admin())
            ->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);

        // The stale window cannot consume a card either.
        $result = $this->pairings()->pair($this->reader('classroom'), 'CLOCKJUMP001');
        $this->assertFalse($result['ok']);
        $this->assertSame('no_session', $result['reason']);
    }

    #[Test]
    public function a_completed_pairing_is_reported_with_its_card_and_reader(): void
    {
        $student = $this->studentWithoutCard();
        $reader = $this->reader('classroom');

        $this->pairings()->arm($student);
        $result = $this->pairings()->pair($reader, 'DESK00062041607');

        $this->assertTrue($result['ok']);

        // Audit trail: the pending row points at the exact cards row.
        $this->assertDatabaseHas('pending_pairings', [
            'id' => $result['pairing']->id,
            'card_id' => $result['card']->id,
            'reader_id' => $reader->id,
        ]);

        $response = $this->actingAs($this->admin())->getStatus()->assertOk();

        $response->assertJsonPath('pending', null)
            ->assertJsonPath('last_pairing.card_uid', 'DESK00062041607')
            ->assertJsonPath('last_pairing.student_name', $student->name)
            ->assertJsonPath('last_pairing.reader_label', $reader->label)
            ->assertJsonCount(1, 'recent_pairings')
            ->assertJsonPath('recent_pairings.0.card_uid', 'DESK00062041607');
    }

    #[Test]
    public function a_rejected_tap_is_stamped_on_the_armed_window_and_reported(): void
    {
        // TASK-014 — the owner's bench scenario: pair one student, then
        // tap the SAME (burned) card for the next one. The device gets
        // 422; the desk must see WHY.
        $reader = $this->reader('classroom');
        $target = $this->studentWithoutCard();

        $this->pairings()->arm($target);
        $result = $this->pairings()->pair($reader, $this->cardUidFor('Maria González'));

        $this->assertFalse($result['ok']);
        $this->assertSame('already_paired', $result['reason']);

        // The rejection is stamped on the pending row; the window stays ARMED.
        $pairing = PendingPairing::latest('id')->first();
        $this->assertNotNull($pairing->last_rejected_at);
        $this->assertSame($this->cardUidFor('Maria González'), $pairing->last_rejected_uid);
        $this->assertSame('already_paired', $pairing->last_rejected_reason);
        $this->assertTrue($pairing->isActive(), 'a rejected tap must NOT consume the armed window');

        $response = $this->actingAs($this->admin())->getStatus()->assertOk();
        $response->assertJsonPath('pending.last_rejection.card_uid', $this->cardUidFor('Maria González'))
            ->assertJsonPath('pending.last_rejection.reason', 'already_paired');
        $this->assertNotNull($response->json('pending.last_rejection.at'));
    }

    #[Test]
    public function a_rejected_tap_leaves_the_window_pairable_and_the_stamp_latest_wins(): void
    {
        $reader = $this->reader('classroom');
        $target = $this->studentWithoutCard();

        $this->pairings()->arm($target);

        // Two rejections in the same window: the latest one is the stamp.
        $this->pairings()->pair($reader, $this->cardUidFor('Maria González'));
        $this->pairings()->pair($reader, $this->cardUidFor('Ana Martínez'));
        $pairing = PendingPairing::latest('id')->first();
        $this->assertSame($this->cardUidFor('Ana Martínez'), $pairing->last_rejected_uid);

        // And the SAME window can still pair a genuinely fresh card.
        $result = $this->pairings()->pair($reader, 'FRESH-AFTER-REJECTION');
        $this->assertTrue($result['ok']);
        $this->assertSame($target->id, $result['card']->student_id);
    }

    #[Test]
    public function a_no_session_tap_has_no_row_to_stamp_and_no_pending_in_the_feed(): void
    {
        // Tap when nothing is armed: the device gets 409 (its own remediation
        // path, TASK-004); there is no armed row to stamp and the feed's
        // pending is null — nothing to report at the desk (by design).
        $result = $this->pairings()->pair($this->reader('classroom'), 'ORPHAN123');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_session', $result['reason']);

        $this->actingAs($this->admin())->getStatus()
            ->assertOk()
            ->assertJsonPath('pending', null);
    }

    #[Test]
    public function the_history_lists_the_most_recent_completions_first(): void
    {
        $reader = $this->reader('classroom');

        foreach (['AAA111', 'BBB222', 'CCC333'] as $i => $uid) {
            $student = $this->studentWithoutCard($i);
            $this->pairings()->arm($student);
            $this->pairings()->pair($reader, $uid);
        }

        $response = $this->actingAs($this->admin())->getStatus()->assertOk();

        $response->assertJsonCount(3, 'recent_pairings')
            ->assertJsonPath('recent_pairings.0.card_uid', 'CCC333')
            ->assertJsonPath('recent_pairings.2.card_uid', 'AAA111');
    }

    private function pairings(): PairingService
    {
        return $this->app->make(PairingService::class);
    }

    /**
     * A student with no card yet (pairing links a FRESH card, and every
     * demo student arrives with one). Same convention as CardPairingTest.
     */
    private function studentWithoutCard(int $n = 0): Student
    {
        $existing = Student::whereDoesntHave('cards')->first();

        if ($existing !== null && $n === 0) {
            return $existing;
        }

        return Student::create([
            'name' => "Estudiante Escritorio {$n}",
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);
    }
}

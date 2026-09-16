<?php

namespace Tests\Feature\Api;

use App\Models\PresenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-047 — taps that are answered but write no events row still leave
 * a `tap_feedback` cue for the realtime speaker bridge; taps that write a
 * row do not (their `tap` frame already carries the cue — no double beep).
 */
class TapFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function tap(string $uid, string $reader = 'classroom')
    {
        return $this->postJson('/api/v1/events/tap', ['credential_uid' => $uid], [
            'Authorization' => 'Bearer '.$this->readerToken($reader),
        ]);
    }

    #[Test]
    public function a_first_tap_leaves_no_cue_and_a_repeat_tap_leaves_an_accepted_one(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $first = $this->tap($uid)->assertOk();
        $this->assertDatabaseCount('tap_feedback', 0);

        $this->tap($uid)->assertOk()->assertJson(['duplicate' => true]);
        $this->tap($uid)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('tap_feedback', 2);
        $this->assertDatabaseHas('tap_feedback', [
            'cue' => 'accepted',
            'reason' => 'duplicate',
            'event_id' => $first->json('event_id'),
            'reader_id' => $this->reader('classroom')->id,
            'school_id' => $this->reader('classroom')->school_id,
        ]);
    }

    #[Test]
    public function unknown_and_inactive_cards_leave_a_rejected_cue(): void
    {
        $this->tap('NOPE123')->assertNotFound();

        $card = $this->cardOf('Ana Martínez');
        $card->update(['status' => 'revoked']);
        $this->tap($card->credential_uid)->assertStatus(404);

        $this->assertDatabaseHas('tap_feedback', ['cue' => 'rejected', 'reason' => 'not_found', 'event_id' => null]);
        $this->assertDatabaseHas('tap_feedback', ['cue' => 'rejected', 'reason' => 'inactive']);
        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_broken_feedback_log_never_fails_the_devices_tap(): void
    {
        $uid = $this->cardUidFor('Maria González');
        $this->tap($uid)->assertOk();

        Schema::drop('tap_feedback');

        $this->tap($uid)->assertOk()->assertJson(['duplicate' => true]);
        $this->tap('NOPE123')->assertNotFound();
    }

    #[Test]
    public function recycling_taps_always_write_rows_and_never_log_a_cue(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $this->tap($uid, 'recycling')->assertOk();
        $this->tap($uid, 'recycling')->assertOk();

        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('tap_feedback', 0);
    }
}

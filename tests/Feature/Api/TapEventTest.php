<?php

namespace Tests\Feature\Api;

use App\Models\PresenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TapEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        // TASK-037 — the fixture owns ALL events (the demo seeder's
        // past-day PAE scenario rows would leak into the counts).
        PresenceEvent::query()->delete();
    }

    #[Test]
    public function a_valid_tap_creates_an_event_and_returns_device_feedback(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $response->assertOk()
            ->assertJsonStructure(['status', 'event_id', 'event_type', 'student_first_name', 'next_step'])
            ->assertJson([
                'status' => 'ok',
                'event_type' => 'CLASS_ATTENDANCE',
                'student_first_name' => 'Maria',
                'next_step' => null,
            ]);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseHas('events', [
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
        ]);
    }

    #[Test]
    public function an_unknown_card_returns_a_clean_404(): void
    {
        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'NOPE123',
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $response->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'message' => 'Card not recognized',
            ]);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function an_inactive_card_is_rejected(): void
    {
        $card = $this->cardOf('Ana Martínez');
        $card->update(['status' => 'revoked']);

        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $response->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'message' => 'Card is not active',
            ]);
    }

    #[Test]
    public function a_missing_bearer_token_returns_401(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'])
            ->assertUnauthorized()
            ->assertJson(['status' => 'error']);
    }

    #[Test]
    public function an_invalid_bearer_token_returns_401(): void
    {
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => 'Bearer not-a-real-key',
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_recycling_tap_signals_awaiting_classification(): void
    {
        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Diego López'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'event_type' => 'RECYCLING_DEPOSIT',
                'next_step' => 'awaiting_classification',
            ])
            ->assertJsonPath('event_id', fn ($id) => is_int($id));

        // No points were awarded at tap time — classification comes first.
        $this->assertDatabaseCount('points_ledger', 0);
    }

    #[Test]
    public function a_client_timestamp_is_honored_when_present(): void
    {
        $clientTime = now()->subMinutes(30)->toIso8601String();

        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
            'client_timestamp' => $clientTime,
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $response->assertOk();

        $event = PresenceEvent::first();
        $this->assertEqualsWithDelta(
            now()->subMinutes(30)->getTimestamp(),
            $event->occurred_at->getTimestamp(),
            5,
            'occurred_at should use the client timestamp (within tolerance).'
        );
        $this->assertSame($clientTime, $event->metadata['client_timestamp']);
    }

    #[Test]
    public function the_tap_uses_the_readers_current_active_mode(): void
    {
        $reader = $this->reader('classroom');
        $reader->update(['active_event_type' => 'PAE_BREAKFAST']);

        // TASK-037 — a relabeled PAE-mode reader routes through the meal
        // engine: the meal comes from the clock (auto-detected), the
        // eligibility rules apply. Freeze a school day inside breakfast.
        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $this->wideMealWindows();

        // Same-day attendance prerequisite: tap class attendance first
        // (the classroom reader — its mode is the reader's own business).
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse('2026-09-01 07:00:00'),
        ]);

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
            'client_timestamp' => '2026-09-01 07:15:00',
        ], ['Authorization' => 'Bearer '.$reader->api_key])
            ->assertOk()
            ->assertJsonPath('event_type', 'PAE_BREAKFAST');

        $this->travelBack();
    }

    #[Test]
    public function validation_errors_are_returned_as_422(): void
    {
        $this->postJson('/api/v1/events/tap', [], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
        ])->assertUnprocessable();
    }
}

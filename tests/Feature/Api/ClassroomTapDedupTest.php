<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PresenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-043 — first tap counts (RT-002 fix).
 *
 * A second classroom tap by the same student for the same type on the
 * same school-local day is a duplicate, not a new event: the original
 * row is returned (the classify idempotency shape) so held cards,
 * hand-retries after timeouts, and replayed requests cannot inflate
 * attendance. Recycling taps stay per-tap (every tap is a deposit).
 */
class ClassroomTapDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function tap(string $uid, ?string $at = null): TestResponse
    {
        $payload = ['credential_uid' => $uid];

        if ($at !== null) {
            $payload['client_timestamp'] = $at;
        }

        return $this->postJson('/api/v1/events/tap', $payload, [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
        ]);
    }

    #[Test]
    public function the_first_tap_counts_and_the_second_is_a_duplicate(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $first = $this->tap($uid);
        $first->assertOk()->assertJson(['status' => 'ok', 'duplicate' => false]);
        $this->assertDatabaseCount('events', 1);

        $second = $this->tap($uid);
        $second->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => true, 'event_id' => $first->json('event_id')]);

        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function a_tap_on_another_day_counts_again(): void
    {
        $uid = $this->cardUidFor('Maria González');

        $this->tap($uid, now()->subDay()->toIso8601String())->assertOk()->assertJson(['duplicate' => false]);
        $this->tap($uid)->assertOk()->assertJson(['duplicate' => false]);

        $this->assertDatabaseCount('events', 2);
    }

    #[Test]
    public function another_student_still_counts(): void
    {
        $this->tap($this->cardUidFor('Maria González'))->assertOk();
        $this->tap($this->cardUidFor('Carlos Pérez'))->assertOk()->assertJson(['duplicate' => false]);

        $this->assertDatabaseCount('events', 2);
    }

    #[Test]
    public function a_second_card_of_the_same_student_is_still_the_same_first_tap(): void
    {
        $student = $this->cardOf('Maria González')->student;
        $secondCard = Card::create([
            'credential_uid' => 'SECOND-CARD-FOR-MARIA',
            'student_id' => $student->id,
        ]);

        $this->tap($this->cardUidFor('Maria González'))->assertOk()->assertJson(['duplicate' => false]);
        $this->tap($secondCard->credential_uid)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function recycling_taps_stay_per_tap_every_tap_is_a_deposit(): void
    {
        $uid = $this->cardUidFor('Diego López');
        $headers = ['Authorization' => 'Bearer '.$this->readerToken('recycling')];

        $this->postJson('/api/v1/events/tap', ['credential_uid' => $uid], $headers)
            ->assertOk()->assertJson(['duplicate' => false]);
        $this->postJson('/api/v1/events/tap', ['credential_uid' => $uid], $headers)
            ->assertOk()->assertJson(['duplicate' => false]);

        $this->assertDatabaseCount('events', 2);
    }
}

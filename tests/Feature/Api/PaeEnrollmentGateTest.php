<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 built the original PAE enrollment gate; TASK-037 turned it
 * into the full meal-serving engine (see MealServingTest for the rule
 * matrix). This file keeps the gate's ORIGINAL contract, restated for
 * the per-meal model: a not-enrolled tap is a 422 with a device-facing
 * localized, MEAL-SPECIFIC message, is persisted as a flagged (auditable)
 * attempt, and never inflates paeCount.
 */
class PaeEnrollmentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        // The fixture owns ALL events (the demo seeder's past-day PAE
        // scenario rows would leak into the counts below).
        PresenceEvent::query()->delete();
        $this->travelTo(Carbon::parse('2026-09-01 07:10:00')); // Tuesday
        $this->wideMealWindows();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function tap(string $key, string $uid, array $headers = [])
    {
        return $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-01 07:15:00',
        ], array_merge(['Authorization' => "Bearer {$key}"], $headers));
    }

    private function uidOf(string $student): string
    {
        return Card::whereHas('student', fn ($q) => $q->where('name', $student))->firstOrFail()->credential_uid;
    }

    private function attend(string $student): void
    {
        PresenceEvent::create([
            'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', $student))->firstOrFail()->id,
            'reader_id' => Reader::where('type', 'classroom')->firstOrFail()->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse('2026-09-01 07:00:00'),
        ]);
    }

    private function paeReader(): Reader
    {
        return $this->schoolReader(
            'Gate Test — Cafeteria',
            [
                'type' => 'pae',
                'active_event_type' => 'PAE_BREAKFAST',
                'api_key' => 'pae-gate-test-key-00000000000001',
            ],
        );
    }

    #[Test]
    public function a_not_enrolled_meal_tap_is_a_flagged_422(): void
    {
        $reader = $this->paeReader();
        $this->attend('Ana Martínez'); // lunch-only: breakfast is not enrolled

        $this->tap($reader->api_key, $this->uidOf('Ana Martínez'))
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'not_enrolled', 'meal' => 'breakfast']);

        // TASK-037 — the attempt IS persisted (auditable, served=false);
        // it just never counts.
        $this->assertDatabaseHas('events', [
            'type' => 'PAE_BREAKFAST',
            'served' => false,
            'reason' => 'not_enrolled',
        ]);
        $this->assertSame(0, PresenceEvent::where('served', true)->where('type', 'PAE_BREAKFAST')->count());
    }

    #[Test]
    public function an_enrolled_present_student_taps_straight_through(): void
    {
        $reader = $this->paeReader();
        $this->attend('Maria González');

        $this->tap($reader->api_key, $this->uidOf('Maria González'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'PAE_BREAKFAST', 'meal' => 'breakfast']);

        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->where('served', true)->count());
    }

    #[Test]
    public function the_rejection_speaks_spanish_and_names_the_meal_on_request(): void
    {
        $reader = $this->paeReader();
        $this->attend('Ana Martínez');

        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->uidOf('Ana Martínez'),
            'client_timestamp' => '2026-09-01 07:15:00',
        ], [
            'Authorization' => "Bearer {$reader->api_key}",
            'Accept-Language' => 'es',
        ]);

        $response->assertStatus(422)->assertJson(['reason' => 'not_enrolled', 'meal' => 'breakfast']);
        $this->assertSame('Ana no está inscrito para el Desayuno', $response->json('message'));
    }

    #[Test]
    public function the_gate_needs_no_special_role_because_devices_have_none(): void
    {
        // Guests (no session at all) reach the device surface — the gate
        // is reader-key auth, and the rejection is identical.
        $reader = $this->paeReader();
        $this->attend('Ana Martínez');

        $this->tap($reader->api_key, $this->uidOf('Ana Martínez'))->assertStatus(422);
    }

    #[Test]
    public function rejected_attempts_never_inflate_meal_counts(): void
    {
        $reader = $this->paeReader();
        $this->attend('Ana Martínez');
        $this->attend('Maria González');

        $this->tap($reader->api_key, $this->uidOf('Ana Martínez'))->assertStatus(422);
        $this->tap($reader->api_key, $this->uidOf('Maria González'))->assertOk();

        $service = new AttendanceService;

        $this->assertSame(1, $service->paeCount('breakfast', '2026-09-01'));
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->where('served', true)->count());
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->where('served', false)->count());
    }
}

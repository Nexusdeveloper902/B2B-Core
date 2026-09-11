<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix C) — the PAE enrollment gate: a PAE-mode reader rejects
 * a non-enrolled tap with a device-facing localized 422, logs the
 * attempt, and records NOTHING (paeCount stays honest). The enrolled
 * happy path taps straight through on the same reader.
 */
class PaeEnrollmentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function paeReader(): Reader
    {
        return Reader::create([
            'label' => 'Gate Test — PAE',
            'type' => 'pae',
            'active_event_type' => 'PAE_BREAKFAST',
            'api_key' => 'pae-gate-test-key-00000000000001',
        ]);
    }

    private function tap(string $key, string $uid)
    {
        return $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
        ], ['Authorization' => "Bearer {$key}"]);
    }

    private function uidOf(string $student): string
    {
        return Card::whereHas('student', fn ($q) => $q->where('name', $student))->firstOrFail()->credential_uid;
    }

    #[Test]
    public function a_non_pae_student_is_rejected_and_logged_without_a_trace_in_events(): void
    {
        $reader = $this->paeReader();
        $eventsBefore = PresenceEvent::count();

        $response = $this->tap($reader->api_key, $this->uidOf('Ana Martínez'));

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'student_not_pae']);

        $this->assertSame($eventsBefore, PresenceEvent::count());
    }

    #[Test]
    public function an_enrolled_student_taps_straight_through(): void
    {
        $reader = $this->paeReader();

        $this->tap($reader->api_key, $this->uidOf('Maria González'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'PAE_BREAKFAST']);

        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->count());
    }

    #[Test]
    public function the_rejection_speaks_spanish_on_request(): void
    {
        $reader = $this->paeReader();

        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->uidOf('Ana Martínez'),
        ], [
            'Authorization' => "Bearer {$reader->api_key}",
            'Accept-Language' => 'es',
        ]);

        $response->assertStatus(422)->assertJson(['reason' => 'student_not_pae']);
        $this->assertStringContainsString('PAE', (string) $response->json('message'));
    }

    #[Test]
    public function the_gate_needs_no_special_role_because_devices_have_none(): void
    {
        // Guests (no session at all) reach the device surface — the gate
        // is reader-key auth, and the rejection is identical.
        $reader = $this->paeReader();

        $this->tap($reader->api_key, $this->uidOf('Ana Martínez'))->assertStatus(422);
    }

    #[Test]
    public function rejected_attempts_never_inflate_meal_counts(): void
    {
        $reader = $this->paeReader();

        $this->tap($reader->api_key, $this->uidOf('Ana Martínez'))->assertStatus(422);
        $this->tap($reader->api_key, $this->uidOf('Maria González'))->assertOk();

        $service = new AttendanceService;

        $this->assertSame(1, $service->paeCount('breakfast', now()->toDateString()));
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->count());
    }
}

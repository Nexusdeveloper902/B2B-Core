<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 — per-card unpair (DELETE /api/v1/admin/cards/{card}), the API
 * half of gap D1. Semantics are the single-card granularity of ADR-023's
 * bulk `cards:unpair`: the row is DELETED (never nulled — a nulled row
 * would still block re-pairing), tap events cascade with the card, and
 * pairing history rows survive with their card link cleared.
 */
class CardUnpairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
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
    public function an_admin_unpairs_one_card_keeping_history_rows_but_cascading_events(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $card = $student->cards()->firstOrFail();

        // The card's tap history + a pairing history row pointing at it.
        PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => 1,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->subDay(),
        ]);
        PendingPairing::create([
            'student_id' => $student->id,
            'reader_id' => 1,
            'card_id' => $card->id,
            'expires_at' => now()->addMinute(),
            'consumed_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/cards/{$card->id}");

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'unpaired' => [
                    'credential_uid' => $card->credential_uid,
                    'student_name' => $student->name,
                ],
            ]);

        // The credential row is GONE (fresh again), not nulled.
        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        // Tap events cascade with the card.
        $this->assertSame(0, PresenceEvent::where('card_id', $card->id)->count());
        // The pairing history row survives as the audit trail, link cleared.
        $this->assertDatabaseHas('pending_pairings', [
            'student_id' => $student->id,
            'card_id' => null,
        ]);
        // The student survives untouched.
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    #[Test]
    public function the_unpaired_credential_can_be_paired_again_immediately(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $card = $student->cards()->firstOrFail();
        $uid = $card->credential_uid;

        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/cards/{$card->id}")
            ->assertOk();

        // Re-pair the same credential to another student (the bench loop):
        // the fresh UID must be accepted again.
        $other = Student::where('name', 'Ana Martínez')->firstOrFail();
        $this->app->make(PairingService::class)->arm($other);
        $pair = $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')]);

        $pair->assertOk();
        $this->assertDatabaseHas('cards', [
            'credential_uid' => $uid,
            'student_id' => $other->id,
        ]);
    }

    #[Test]
    public function the_unpair_surface_rejects_guests(): void
    {
        // Before any actingAs (session stickiness).
        $card = Card::firstOrFail();

        $this->deleteJson("/api/v1/admin/cards/{$card->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('cards', ['id' => $card->id]);
    }

    #[Test]
    public function the_unpair_surface_rejects_teachers(): void
    {
        $card = Card::firstOrFail();

        $this->actingAs($this->teacher())
            ->deleteJson("/api/v1/admin/cards/{$card->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('cards', ['id' => $card->id]);
    }

    #[Test]
    public function an_unknown_card_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/admin/cards/999999')
            ->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-010 — card pairing (two-step arm-then-pair, ADR-020).
 *
 * Covers: the happy path (arm → pair a fresh card), the no-active-session
 * conflict (409), the already-paired rejection (422), expiry semantics,
 * one-shot consumption, auth on both sides, and bilingual messages.
 */
class CardPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function armPairing(Student $student, array $headers = []): TestResponse
    {
        return $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/students/{$student->id}/arm-pairing", [], $headers);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@presence.test')->firstOrFail();
    }

    private function pair(string $credentialUid, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => $credentialUid,
        ], array_merge(['Authorization' => 'Bearer '.$this->readerToken('classroom')], $headers));
    }

    /**
     * A student with no card yet: uses a seeded one if present, otherwise
     * creates one (all four demo students arrive with cards, and each
     * test that pairs a card burns a "fresh student").
     */
    private function studentWithoutCard(): Student
    {
        $existing = Student::whereDoesntHave('cards')->first();

        if ($existing !== null) {
            return $existing;
        }

        static $n = 0;
        $n++;

        return Student::create([
            'name' => "Estudiante Nueva {$n}",
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);
    }

    #[Test]
    public function arming_then_pairing_a_fresh_card_links_it_to_the_student(): void
    {
        $student = $this->studentWithoutCard();

        $arm = $this->armPairing($student);
        $arm->assertOk()
            ->assertJsonStructure(['status', 'student_id', 'expires_at'])
            ->assertJson([
                'status' => 'ok',
                'student_id' => $student->id,
            ]);

        // The window is real (45 s by default) and ISO 8601.
        $this->assertSame(
            now()->addSeconds(45)->getTimestamp(),
            $arm->json('expires_at') ? strtotime($arm->json('expires_at')) : 0,
            'expires_at should be ~45 s in the future'
        );

        $response = $this->pair('NEWCARD1234A');

        $response->assertOk()
            ->assertJsonStructure(['status', 'paired_student_name', 'student_id'])
            ->assertJson([
                'status' => 'ok',
                'paired_student_name' => $student->name,
                'student_id' => $student->id,
            ]);

        // The card row links the credential to the student, active by default.
        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'NEWCARD1234A',
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        // The pending pairing is consumed and stamped with the reader.
        $pairing = PendingPairing::where('student_id', $student->id)->first();
        $this->assertNotNull($pairing->consumed_at);
        $this->assertEquals($this->reader('classroom')->id, $pairing->reader_id);
    }

    #[Test]
    public function a_paired_card_immediately_works_for_taps(): void
    {
        $student = $this->studentWithoutCard();
        $this->armPairing($student);
        $this->pair('NEWCARD5678B')->assertOk();

        // The whole point: pairing makes the card usable in operation mode.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'NEWCARD5678B',
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'student_first_name' => $student->firstName(),
            ]);
    }

    #[Test]
    public function pairing_with_no_active_session_returns_a_clean_409(): void
    {
        $response = $this->pair('FRESHCARD9999');

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'message' => 'No pairing session active',
            ]);

        // Nothing was created.
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'FRESHCARD9999']);
    }

    #[Test]
    public function an_already_paired_card_is_rejected_without_reassignment(): void
    {
        $owner = $this->cardOf('Maria González');   // existing active card
        $target = $this->studentWithoutCard();

        $this->armPairing($target);
        $response = $this->pair($owner->credential_uid);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'Card already paired',
            ]);

        // The card is NOT reassigned: still linked to its original student.
        $this->assertDatabaseHas('cards', [
            'credential_uid' => $owner->credential_uid,
            'student_id' => $owner->student_id,
        ]);

        // The pending session stays armed — the operator can scan a
        // DIFFERENT fresh card immediately.
        $pairing = PendingPairing::where('student_id', $target->id)->first();
        $this->assertNull($pairing->consumed_at);

        $this->pair('ANOTHERFRESH1')->assertOk();
        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'ANOTHERFRESH1',
            'student_id' => $target->id,
        ]);
    }

    #[Test]
    public function an_inactive_card_is_also_rejected_as_already_paired(): void
    {
        // A lost/revoked credential must not silently re-link to a new
        // student; replacement cards are NEW credentials by design.
        $card = $this->cardOf('Ana Martínez');
        $card->update(['status' => 'revoked']);

        $target = $this->studentWithoutCard();
        $this->armPairing($target);

        $this->pair($card->credential_uid)->assertStatus(422)
            ->assertJson(['message' => 'Card already paired']);
    }

    #[Test]
    public function an_expired_pending_pairing_is_treated_as_inactive(): void
    {
        $student = $this->studentWithoutCard();
        $this->armPairing($student);

        PendingPairing::where('student_id', $student->id)
            ->update(['expires_at' => now()->subSeconds(1)]);

        $this->pair('TOOLATECARD1')->assertStatus(409)
            ->assertJson(['message' => 'No pairing session active']);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'TOOLATECARD1']);
    }

    #[Test]
    public function a_consumed_pairing_is_one_shot(): void
    {
        $student = $this->studentWithoutCard();
        $this->armPairing($student);
        $this->pair('ONESHOTCARD1')->assertOk();

        // No session is armed anymore: a second card gets the 409.
        $this->pair('ONESHOTCARD2')->assertStatus(409);
    }

    #[Test]
    public function the_most_recent_armed_pairing_wins(): void
    {
        $first = $this->studentWithoutCard();
        $second = Student::whereDoesntHave('cards')
            ->where('id', '!=', $first->id)->first();

        if ($second === null) {
            $second = Student::create([
                'name' => 'Estudiante Segunda',
                'grade' => '5°',
                'pae_enrolled' => false,
            ]);
        }

        $this->armPairing($first);
        $this->armPairing($second);

        $this->pair('LATESTWINS12')->assertOk()
            ->assertJson([
                'student_id' => $second->id,
                'paired_student_name' => $second->name,
            ]);
    }

    #[Test]
    public function arming_requires_the_admin_role(): void
    {
        $student = $this->studentWithoutCard();

        // Guest → 401 (must be asserted BEFORE any actingAs in the test,
        // since actingAs authenticates the whole test session)
        $this->postJson("/api/v1/admin/students/{$student->id}/arm-pairing")
            ->assertUnauthorized();

        // Teacher → 403
        $this->actingAs($this->teacher())
            ->postJson("/api/v1/admin/students/{$student->id}/arm-pairing")
            ->assertForbidden();

        // Unknown student → 404 (route model binding)
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students/999999/arm-pairing')
            ->assertNotFound();

        $this->assertDatabaseCount('pending_pairings', 0);
    }

    #[Test]
    public function arming_works_with_a_personal_access_token(): void
    {
        // The dual-auth convention: dashboard session OR PAT.
        $token = $this->admin()->createToken('pairing-test')->plainTextToken;
        $student = $this->studentWithoutCard();

        $this->postJson("/api/v1/admin/students/{$student->id}/arm-pairing", [], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseCount('pending_pairings', 1);
    }

    #[Test]
    public function pairing_requires_a_valid_reader_bearer_key(): void
    {
        $student = $this->studentWithoutCard();
        $this->armPairing($student);

        // Missing key → 401
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ])->assertUnauthorized();

        // Invalid key → 401
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ], ['Authorization' => 'Bearer not-a-real-key'])
            ->assertUnauthorized();

        // A dashboard user's PAT is NOT a reader key → 401 (the reader key
        // IS the reader identity; user tokens are a different plane).
        $pat = $this->admin()->createToken('not-a-reader')->plainTextToken;
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'ANYCARD00001',
        ], ['Authorization' => "Bearer {$pat}"])
            ->assertUnauthorized();

        // No new card was created by any of the rejected attempts (the
        // 4 seeded cards remain, but no ANYCARD00001 row).
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'ANYCARD00001']);
    }

    #[Test]
    public function pairing_messages_localize_via_accept_language(): void
    {
        $this->pair('LANGTESTCARD', ['Accept-Language' => 'es'])
            ->assertStatus(409)
            ->assertJson(['message' => 'No hay ninguna sesión de emparejamiento activa']);

        $this->cardOf('Maria González'); // ensure a paired card exists
        $this->studentWithoutCard();
        $this->armPairing($this->studentWithoutCard());
        $owner = $this->cardOf('Maria González');

        $this->pair($owner->credential_uid, ['Accept-Language' => 'es'])
            ->assertStatus(422)
            ->assertJson(['message' => 'La tarjeta ya está emparejada']);
    }

    #[Test]
    public function arm_and_pair_payloads_are_validated(): void
    {
        // Pairing without a credential_uid → 422 (validation, not 409):
        // validation runs before the controller logic.
        $student = $this->studentWithoutCard();
        $this->armPairing($student);

        $this->postJson('/api/v1/admin/cards/pair', [], [
            'Authorization' => 'Bearer '.$this->readerToken('classroom'),
        ])->assertStatus(422);
    }

    #[Test]
    public function concurrent_pair_taps_cannot_double_consume_one_session(): void
    {
        $student = $this->studentWithoutCard();
        $this->armPairing($student);

        // Two cards pairing "simultaneously" against one session: the
        // row lock serializes them; the second gets 409 (session consumed)
        // — never two cards linked by one pairing.
        $first = $this->pair('RACEWINNER01');
        $first->assertOk();

        $second = $this->pair('RACELOSER02');
        $second->assertStatus(409);

        $this->assertDatabaseHas('cards', ['credential_uid' => 'RACEWINNER01', 'student_id' => $student->id]);
        $this->assertDatabaseMissing('cards', ['credential_uid' => 'RACELOSER02']);
    }
}

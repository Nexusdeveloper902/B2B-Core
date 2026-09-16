<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HCE integration — the Android phone as a first-class Pulse credential.
 *
 * The phone is detected over ISO-DEP (SELECT AID F0010203040506 +
 * CHALLENGE) and its application-level credential id arrives as
 * credential_uid with credential_kind=hce. The backend treats it like
 * any card from there: same pair/tap/unpair/revoke lifecycle, same
 * endpoints, same event spine. The RF UID is never identity — Android
 * randomizes it per tap and the backend never receives it.
 */
class HceCredentialTest extends TestCase
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

    private function readerHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->readerToken('classroom')];
    }

    private function studentWithoutCard(): Student
    {
        $existing = Student::whereDoesntHave('cards')->first();

        return $existing ?? $this->schoolStudent([
            'name' => 'Estudiante HCE '.uniqid(),
            'grade' => '5°',
            'pae_breakfast_enrolled' => false,
            'pae_lunch_enrolled' => false,
        ]);
    }

    private function arm(Student $student): void
    {
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/students/{$student->id}/arm-pairing")
            ->assertOk();
    }

    #[Test]
    public function pairing_a_phone_with_kind_hce_stores_the_kind_and_taps_work(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'TEST-ANDROID-001',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'student_id' => $student->id]);

        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'TEST-ANDROID-001',
            'kind' => 'hce',
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        // The paired phone taps exactly like a physical card.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'TEST-ANDROID-001',
        ], $this->readerHeaders())
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'student_first_name' => $student->firstName(),
            ]);
    }

    #[Test]
    public function pairing_without_a_kind_defaults_to_physical(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        // Old firmware sends no kind — byte-for-byte the old contract.
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'PLAINUID12345',
        ], $this->readerHeaders())->assertOk();

        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'PLAINUID12345',
            'kind' => 'physical',
        ]);
    }

    #[Test]
    public function an_unknown_kind_is_rejected_by_validation(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);

        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'WEIRDKIND0001',
            'credential_kind' => 'nfc-quantum',
        ], $this->readerHeaders())->assertStatus(422);

        $this->assertDatabaseMissing('cards', ['credential_uid' => 'WEIRDKIND0001']);
    }

    #[Test]
    public function a_revoked_phone_credential_is_rejected_and_repairing_works_after_unpair(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'HCE-REVOKE-ME-01',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())->assertOk();

        // Revoke: taps stop working, like a revoked physical card.
        Card::where('credential_uid', 'HCE-REVOKE-ME-01')->firstOrFail()
            ->update(['status' => 'revoked']);

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'HCE-REVOKE-ME-01',
        ], $this->readerHeaders())->assertStatus(404);

        // Unpair (delete the row): the credential id becomes fresh again…
        $card = Card::where('credential_uid', 'HCE-REVOKE-ME-01')->firstOrFail();
        $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/cards/{$card->id}")
            ->assertOk();

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'HCE-REVOKE-ME-01',
        ], $this->readerHeaders())->assertStatus(404);

        // …and re-pairs to another student, kind preserved.
        $other = $this->studentWithoutCard();
        $this->arm($other);
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'HCE-REVOKE-ME-01',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())
            ->assertOk()
            ->assertJson(['student_id' => $other->id]);

        $this->assertDatabaseHas('cards', [
            'credential_uid' => 'HCE-REVOKE-ME-01',
            'kind' => 'hce',
            'student_id' => $other->id,
        ]);
    }

    #[Test]
    public function identification_does_not_depend_on_the_rf_uid(): void
    {
        // The HCE credential id is NOT a hex RF UID — it contains dashes
        // and would never match the RC522's uppercase-hex UID strings.
        // Pairing + tapping it end-to-end proves the identity comes from
        // the application-level APDU credential, never the RF layer:
        // the backend has no rf_uid column, accepts no rf_uid input, and
        // resolves solely on credential_uid.
        $this->assertFalse(Schema::hasColumn('cards', 'rf_uid'));

        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'HCE-PHONE-CRED-9',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())->assertOk();

        // Two taps — as if from two different randomized RF UIDs — resolve
        // to the same student, because the RF UID is not part of the lookup.
        foreach ([1, 2] as $tap) {
            $this->postJson('/api/v1/events/tap', [
                'credential_uid' => 'HCE-PHONE-CRED-9',
            ], $this->readerHeaders())
                ->assertOk()
                ->assertJson(['student_first_name' => $student->firstName()]);
        }

        $this->assertSame(
            $student->id,
            Card::where('credential_uid', 'HCE-PHONE-CRED-9')->firstOrFail()->student_id
        );
    }

    #[Test]
    public function the_pairing_status_feed_reports_the_credential_kind(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'HCE-STATUS-KIND1',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())->assertOk();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/pairing/status')
            ->assertOk()
            ->assertJsonPath('last_pairing.card_uid', 'HCE-STATUS-KIND1')
            ->assertJsonPath('last_pairing.card_kind', 'hce')
            ->assertJsonPath('recent_pairings.0.card_kind', 'hce');
    }

    #[Test]
    public function the_pairing_desk_badges_phone_credentials(): void
    {
        $student = $this->studentWithoutCard();
        $this->arm($student);
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'HCE-DESK-BADGE-1',
            'credential_kind' => 'hce',
        ], $this->readerHeaders())->assertOk();

        // EN desk: the roster chip + history badge the phone kind.
        $this->actingAs($this->admin())
            ->get(route('admin.pairing', ['grade' => $student->grade]))
            ->assertOk()
            ->assertSee('HCE-DESK-BADGE-1')
            ->assertSee('Phone');

        // ES desk: the badge localizes (web locale is session-persisted
        // via /locale/{locale} — same pattern as AdminPairingDeskTest).
        $this->actingAs($this->admin())->get('/locale/es');
        $this->actingAs($this->admin())
            ->get(route('admin.pairing', ['grade' => $student->grade]))
            ->assertOk()
            ->assertSee('Teléfono');
    }
}

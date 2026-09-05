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
                'pending' => ['student_id', 'student_name', 'expires_at', 'seconds_left'],
                'last_pairing',
                'recent_pairings',
            ]);

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

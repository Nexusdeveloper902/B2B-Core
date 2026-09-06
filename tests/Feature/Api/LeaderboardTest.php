<?php

namespace Tests\Feature\Api;

use App\Models\PointsLedger;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-025 item 4 — the leaderboard endpoint (spec §22/§28): ranking
 * derived from the real ledger, deterministic tie-breaking, and the
 * student's own standing resolved from the ACCOUNT, never a parameter.
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_leaderboard_ranks_students_from_the_real_ledger(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $carlos = $this->studentIdOf('Carlos Pérez');
        $ana = $this->studentIdOf('Ana Martínez');

        $this->award($maria, 10);
        $this->award($carlos, 25);
        $this->award($ana, 10);

        $response = $this->actingAs($this->admin())->getJson('/api/v1/recycling/leaderboard');

        $response->assertOk()->assertJsonStructure([
            'status',
            'entries' => [['rank', 'student_id', 'student_name', 'class_name', 'points']],
        ]);

        $entries = collect($response->json('entries'));

        // Ordered by points; the tie (Maria/Ana, 10 each) breaks
        // deterministically by student id — and shares the rank.
        $this->assertSame($carlos, $entries[0]['student_id']);
        $this->assertSame(1, $entries[0]['rank']);
        $this->assertSame(25, $entries[0]['points']);

        $this->assertSame(min($maria, $ana), $entries[1]['student_id']);
        $this->assertSame(2, $entries[1]['rank']);
        $this->assertSame(10, $entries[1]['points']);
        $this->assertSame(max($maria, $ana), $entries[2]['student_id']);
        $this->assertSame(2, $entries[2]['rank'], 'equal points share the rank (competition ranking)');
        $this->assertSame(10, $entries[2]['points']);
    }

    #[Test]
    public function a_student_receives_their_own_standing_from_their_account(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $this->award($maria, 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 25);

        $response = $this->actingAs($this->studentUser('maria'))->getJson('/api/v1/recycling/leaderboard');

        $response->assertOk();
        $this->assertSame($maria, $response->json('me.student_id'));
        $this->assertSame(2, $response->json('me.rank'));
        $this->assertSame(10, $response->json('me.points'));
    }

    #[Test]
    public function guests_and_roles_are_gated(): void
    {
        $this->getJson('/api/v1/recycling/leaderboard')->assertUnauthorized();
        $this->actingAs($this->studentUser('maria'))->getJson('/api/v1/recycling/leaderboard')->assertOk();
    }

    #[Test]
    public function the_limit_parameter_is_clamped(): void
    {
        $this->award($this->studentIdOf('Maria González'), 10);

        $response = $this->actingAs($this->admin())->getJson('/api/v1/recycling/leaderboard?limit=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, count($response->json('entries')));
    }

    // ------------------------------------------------------------- helpers

    private function admin()
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function studentUser(string $slug)
    {
        return User::where('email', "{$slug}@presence.test")->firstOrFail();
    }

    private function studentIdOf(string $name): int
    {
        return Student::where('name', $name)->firstOrFail()->id;
    }

    private function award(int $studentId, int $points): void
    {
        PointsLedger::create([
            'student_id' => $studentId,
            'delta' => $points,
            'reason' => 'recycling_deposit',
        ]);
    }
}

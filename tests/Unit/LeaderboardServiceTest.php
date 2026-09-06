<?php

namespace Tests\Unit;

use App\Models\PointsLedger;
use App\Models\Student;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-025 item 4 — LeaderboardService ranking semantics (spec §22/§28):
 * ledger-derived totals, deterministic ordering (points DESC, id ASC),
 * competition ranks on ties (1, 2, 2, 4).
 */
class LeaderboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaderboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->service = app(LeaderboardService::class);
    }

    #[Test]
    public function ties_share_a_rank_and_the_next_rank_skips(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $carlos = $this->studentIdOf('Carlos Pérez');
        $ana = $this->studentIdOf('Ana Martínez');
        $diego = $this->studentIdOf('Diego López');

        $this->award($diego, 30);   // 1st
        $this->award($maria, 10);   // tie 2nd
        $this->award($ana, 10);     // tie 2nd
        // Carlos: 0 points — still on the board (SUM 0).

        $top = $this->service->top();

        $this->assertSame(1, $top[0]['rank']);
        $this->assertSame($diego, $top[0]['student_id']);

        $this->assertSame(2, $top[1]['rank']);
        $this->assertSame(2, $top[2]['rank']);
        $this->assertEqualsCanonicalizing(
            [min($maria, $ana), max($maria, $ana)],
            [$top[1]['student_id'], $top[2]['student_id']],
            'ties order deterministically by student id',
        );

        $this->assertSame(4, $top[3]['rank'], 'competition ranking: the rank after a tie of 2 skips ahead');
        $this->assertSame($carlos, $top[3]['student_id']);
        $this->assertSame(0, $top[3]['points']);
    }

    #[Test]
    public function rank_of_returns_the_students_own_standing(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $this->award($this->studentIdOf('Carlos Pérez'), 25);
        $this->award($maria->id, 10);

        $standing = $this->service->rankOf($maria);

        $this->assertSame(2, $standing['rank']);
        $this->assertSame(10, $standing['points']);
    }

    #[Test]
    public function a_student_without_movements_ranks_last_with_zero(): void
    {
        $this->award($this->studentIdOf('Maria González'), 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 5);
        $this->award($this->studentIdOf('Diego López'), 3);

        $ana = Student::where('name', 'Ana Martínez')->firstOrFail();

        $standing = $this->service->rankOf($ana);

        $this->assertSame(4, $standing['rank']);
        $this->assertSame(0, $standing['points']);
    }

    #[Test]
    public function the_limit_is_respected(): void
    {
        $this->award($this->studentIdOf('Maria González'), 5);

        $this->assertCount(2, $this->service->top(2));
    }

    #[Test]
    public function spend_movements_count_against_the_total(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $this->award($maria, 25);

        PointsLedger::create([
            'student_id' => $maria,
            'delta' => -5,
            'reason' => 'redemption',
        ]);

        $top = $this->service->top();

        $this->assertSame(20, $top[0]['points']);
    }

    // ------------------------------------------------------------- helpers

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

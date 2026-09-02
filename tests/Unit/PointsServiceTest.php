<?php

namespace Tests\Unit;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Reward;
use App\Models\Student;
use App\Services\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointsServiceTest extends TestCase
{
    use RefreshDatabase;

    private PointsService $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->points = app(PointsService::class);
        $this->seedDemo();
    }

    public function test_balance_starts_at_zero(): void
    {
        $student = Student::first();

        $this->assertSame(0, $this->points->balance($student));
    }

    public function test_balance_is_the_sum_of_deltas(): void
    {
        $student = Student::first();

        $event = PresenceEvent::first() ?? $this->makeEvent($student);
        $this->points->awardRecyclingPoints($student, $event, MaterialClass::Plastic); // +10
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Metal); // +15

        $reward = Reward::orderBy('point_cost')->first(); // 5 pts
        $this->points->spendOnReward($student, $reward); // -5

        $this->assertSame(20, $this->points->balance($student));
    }

    public function test_recycling_points_match_the_configured_table(): void
    {
        $student = Student::first();
        $event = $this->makeEvent($student);

        foreach (MaterialClass::cases() as $material) {
            $awarded = $this->points->awardRecyclingPoints(
                Student::create(['name' => 'L '.$material->value, 'grade' => '5°', 'class_id' => 1]),
                $event,
                $material
            );

            $this->assertSame(
                config("recycling.points.{$material->value}"),
                $awarded,
                "Points for [{$material->value}] must match config/recycling.php"
            );
        }
    }

    public function test_spend_on_reward_deducts_and_records_ledger(): void
    {
        $student = Student::first();
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Metal); // +15

        $reward = Reward::where('point_cost', 5)->firstOrFail();

        $result = $this->points->spendOnReward($student, $reward);

        $this->assertTrue($result['ok']);
        $this->assertSame(10, $result['balance']);

        $ledger = PointsLedger::find($result['ledger_id']);
        $this->assertSame(-5, $ledger->delta);
        $this->assertSame('redemption', $ledger->reason);
        $this->assertSame($reward->id, $ledger->reward_id);
        $this->assertSame(10, $this->points->balance($student));
    }

    public function test_spend_on_reward_fails_cleanly_when_insufficient(): void
    {
        $student = Student::first();
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Plastic); // +10

        $reward = Reward::where('point_cost', 50)->firstOrFail();

        $result = $this->points->spendOnReward($student, $reward);

        $this->assertFalse($result['ok']);
        $this->assertSame(10, $result['balance']);
        $this->assertSame(40, $result['shortfall']);

        // No ledger entry for a failed attempt.
        $this->assertSame(1, PointsLedger::where('student_id', $student->id)->count());
    }

    public function test_zero_point_materials_do_not_create_ledger_noise(): void
    {
        $student = Student::first();
        $this->points->awardRecyclingPoints($student, $this->makeEvent($student), MaterialClass::Other); // 0

        $this->assertSame(0, PointsLedger::where('student_id', $student->id)->count());
        $this->assertSame(0, $this->points->balance($student));
    }

    private function makeEvent(Student $student): PresenceEvent
    {
        return PresenceEvent::create([
            'card_id' => $student->cards()->first()->id,
            'reader_id' => Reader::first()->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);
    }
}

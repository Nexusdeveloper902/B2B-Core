<?php

namespace Tests\Feature\Api;

use App\Models\PointsLedger;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function an_admin_can_redeem_a_reward_when_the_balance_is_sufficient(): void
    {
        $student = $this->cardOf('Maria González')->student;
        $student->pointsLedger()->create(['delta' => 60, 'reason' => 'test_seed']);
        $reward = Reward::where('point_cost', 50)->firstOrFail();

        $response = $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => $reward->id,
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'new_balance' => 10,
            ])
            ->assertJsonPath('reward.name', $reward->name);

        $this->assertDatabaseHas('points_ledger', [
            'student_id' => $student->id,
            'delta' => -50,
            'reason' => 'redemption',
            'reward_id' => $reward->id,
        ]);
    }

    #[Test]
    public function a_teacher_can_also_redeem(): void
    {
        $student = $this->cardOf('Carlos Pérez')->student;
        $student->pointsLedger()->create(['delta' => 30, 'reason' => 'test_seed']);
        $reward = Reward::where('point_cost', 20)->firstOrFail();

        $this->actingAs($this->user('teacher'))
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => $reward->id,
            ])->assertOk()->assertJsonPath('new_balance', 10);
    }

    #[Test]
    public function redemption_fails_cleanly_with_the_shortfall_when_insufficient(): void
    {
        $student = $this->cardOf('Ana Martínez')->student;
        $student->pointsLedger()->create(['delta' => 10, 'reason' => 'test_seed']);
        $reward = Reward::where('point_cost', 50)->firstOrFail();

        $response = $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => $reward->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'current_balance' => 10,
                'reward_cost' => 50,
                'shortfall' => 40,
            ])
            ->assertJsonPath('message', 'Insufficient points: 40 more needed');

        // No spend row for a failed redemption.
        $this->assertSame(
            1,
            PointsLedger::where('student_id', $student->id)->count(),
            'Only the seed row should exist — no redemption ledger entry on failure.'
        );
    }

    #[Test]
    public function guests_are_rejected(): void
    {
        $student = $this->cardOf('Ana Martínez')->student;
        $reward = Reward::first();

        $this->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
        ])->assertUnauthorized();
    }

    #[Test]
    public function unknown_rewards_are_validation_errors(): void
    {
        $student = $this->cardOf('Ana Martínez')->student;

        $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => 99999,
            ])->assertUnprocessable();
    }

    #[Test]
    public function the_ledger_balance_is_always_the_sum_of_deltas(): void
    {
        $student = $this->cardOf('Diego López')->student;
        $student->pointsLedger()->createMany([
            ['delta' => 15, 'reason' => 'recycling_deposit'],
            ['delta' => 5, 'reason' => 'recycling_deposit'],
            ['delta' => -5, 'reason' => 'redemption', 'reward_id' => Reward::where('point_cost', 5)->first()->id],
        ]);

        $balance = (int) PointsLedger::where('student_id', $student->id)->sum('delta');
        $this->assertSame(15, $balance);

        // Spend the rest via the endpoint and confirm the invariant holds.
        $this->actingAs($this->user('admin'))
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => Reward::where('point_cost', 5)->first()->id,
            ])->assertOk()->assertJsonPath('new_balance', 10);

        $this->assertSame(10, (int) PointsLedger::where('student_id', $student->id)->sum('delta'));
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

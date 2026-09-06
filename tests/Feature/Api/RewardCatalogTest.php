<?php

namespace Tests\Feature\Api;

use App\Models\PointsLedger;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-025 item 7 — the rewards catalog rules (spec §19/§20): stock
 * enforcement (atomic, no over-redemption), the active switch, the
 * duplicate window (double-submit protection), and the first-class
 * reward_redemptions record.
 */
class RewardCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function limited_stock_decrements_and_then_runs_out(): void
    {
        // "Early lunch pass" seeds with stock 3.
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $reward = Reward::where('name', 'Early lunch pass')->firstOrFail();
        $this->assertSame(3, $reward->stock);

        $this->award($student->id, 100);
        $token = ['Authorization' => 'Bearer '.$this->readerToken('recycling')];
        $auth = $this->actingAs($this->admin());

        // Redeem three times — each one a DISTINCT request_id (three
        // intentional purchases; the idempotency key protects retries,
        // not repeat purchases) — each takes a unit.
        for ($i = 0; $i < 3; $i++) {
            $auth->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => $reward->id,
                'request_id' => "stock-test-{$i}",
            ])->assertOk();
        }

        $this->assertSame(0, $reward->fresh()->stock);

        // The fourth attempt: out of stock, nothing spent.
        $response = $auth->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'reason' => 'out_of_stock',
            ]);

        $this->assertSame(3, RewardRedemption::where('reward_id', $reward->id)->count());
        $this->assertSame(100 - 3 * $reward->point_cost, (int) PointsLedger::where('student_id', $student->id)->sum('delta'));
    }

    #[Test]
    public function an_inactive_reward_is_unredeemable(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $reward = Reward::where('name', 'Raffle entry')->firstOrFail();
        $this->award($student->id, 100);

        $reward->update(['active' => false]);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/students/{$student->id}/redeem", [
                'reward_id' => $reward->id,
            ])
            ->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'reason' => 'inactive',
            ]);

        $this->assertSame(0, RewardRedemption::count());
        $this->assertSame(100, (int) PointsLedger::where('student_id', $student->id)->sum('delta'));
    }

    #[Test]
    public function a_double_submit_inside_the_window_is_rejected_without_side_effects(): void
    {
        config(['recycling.redemption.duplicate_window_seconds' => 30]);

        $student = Student::where('name', 'Maria González')->firstOrFail();
        $reward = Reward::where('name', 'Leaderboard shout-out')->firstOrFail(); // unlimited stock
        $this->award($student->id, 100);
        $auth = $this->actingAs($this->admin());

        $first = $auth->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
        ]);
        $first->assertOk();
        $redemptionId = $first->json('redemption_id');
        $this->assertNotNull($redemptionId, 'a first-class redemption row is returned');

        // The immediate repeat (double-click / device retry): rejected,
        // no second spend, no second redemption row.
        $second = $auth->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
        ]);

        $second->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'reason' => 'duplicate',
            ]);

        $this->assertSame(1, RewardRedemption::where('reward_id', $reward->id)->count());
        $this->assertSame(100 - $reward->point_cost, (int) PointsLedger::where('student_id', $student->id)->sum('delta'));
    }

    #[Test]
    public function redemptions_after_the_window_are_new_wishes(): void
    {
        config(['recycling.redemption.duplicate_window_seconds' => 30]);

        $student = Student::where('name', 'Maria González')->firstOrFail();
        $reward = Reward::where('name', 'Leaderboard shout-out')->firstOrFail();
        $this->award($student->id, 100);
        $auth = $this->actingAs($this->admin());

        $auth->postJson("/api/v1/students/{$student->id}/redeem", ['reward_id' => $reward->id])->assertOk();

        $this->travel(31)->seconds();

        $auth->postJson("/api/v1/students/{$student->id}/redeem", ['reward_id' => $reward->id])->assertOk();

        $this->assertSame(2, RewardRedemption::where('reward_id', $reward->id)->count());
    }

    #[Test]
    public function a_replayed_request_id_is_true_idempotency_not_a_second_charge(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $reward = Reward::where('name', 'Raffle entry')->firstOrFail();
        $this->award($student->id, 100);
        $auth = $this->actingAs($this->admin());

        $first = $auth->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
            'request_id' => 'desk-button-42',
        ]);
        $first->assertOk()->assertJson(['status' => 'ok', 'replayed' => false]);

        // The SAME request_id again (double-click, device retry): the
        // original answer replays, nothing is charged twice.
        $replay = $auth->postJson("/api/v1/students/{$student->id}/redeem", [
            'reward_id' => $reward->id,
            'request_id' => 'desk-button-42',
        ]);

        $replay->assertOk()->assertJson([
            'status' => 'ok',
            'replayed' => true,
            'redemption_id' => $first->json('redemption_id'),
        ]);

        $this->assertSame(1, RewardRedemption::where('reward_id', $reward->id)->count());
        $this->assertSame(100 - $reward->point_cost, (int) PointsLedger::where('student_id', $student->id)->sum('delta'));
    }

    // ------------------------------------------------------------- helpers

    private function admin()
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
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

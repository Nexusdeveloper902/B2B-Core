<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reward;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Points accounting (Phases C & D). Every movement goes through the
 * append-only points_ledger; balances are always SUM(delta).
 */
class PointsService
{
    public function balance(Student $student): int
    {
        return (int) PointsLedger::where('student_id', $student->id)->sum('delta');
    }

    /**
     * Award recycling points for a classified deposit (earn half of the loop).
     */
    public function awardRecyclingPoints(Student $student, PresenceEvent $event, MaterialClass $material): int
    {
        $points = $material->points();

        if ($points > 0) {
            PointsLedger::create([
                'student_id' => $student->id,
                'delta' => $points,
                'reason' => 'recycling_deposit',
                'event_id' => $event->id,
            ]);
        }

        return $points;
    }

    /**
     * Attempt to spend points on a reward (spend half of the loop).
     *
     * @return array{ok: true, balance: int, ledger_id: int}
     *                                                       | array{ok: false, balance: int, shortfall: int}
     */
    public function spendOnReward(Student $student, Reward $reward): array
    {
        return DB::transaction(function () use ($student, $reward) {
            // Re-read the balance inside the transaction to avoid a
            // double-spend race between two simultaneous desk redemptions.
            $balance = (int) PointsLedger::where('student_id', $student->id)
                ->lockForUpdate()
                ->sum('delta');

            if ($balance < $reward->point_cost) {
                return [
                    'ok' => false,
                    'balance' => $balance,
                    'shortfall' => $reward->point_cost - $balance,
                ];
            }

            $ledger = PointsLedger::create([
                'student_id' => $student->id,
                'delta' => -$reward->point_cost,
                'reason' => 'redemption',
                'reward_id' => $reward->id,
            ]);

            return [
                'ok' => true,
                'balance' => $balance - $reward->point_cost,
                'ledger_id' => $ledger->id,
            ];
        });
    }
}

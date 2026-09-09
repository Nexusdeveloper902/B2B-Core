<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\RecyclingUpdate;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Student;
use App\Services\Realtime\RecyclingUpdateLog;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Points accounting (Phases C & D). Every movement goes through the
 * append-only points_ledger; balances are always SUM(delta).
 */
class PointsService
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function balance(Student $student): int
    {
        return (int) PointsLedger::where('student_id', $student->id)->sum('delta');
    }

    /**
     * Award recycling points for a classified deposit (earn half of the loop).
     * Called inside ClassificationService's award transaction — frames ride it.
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
     * TASK-025 item 7 (spec §19/§20): the spend transaction enforces the
     * catalog rules — inactive rewards, limited stock (atomic decrement —
     * the over-redemption guard), double-submit protection (a
     * client-supplied request_id is TRUE idempotency: a replay returns
     * the original answer, never a second charge; without one, a
     * same-student+reward window heuristic applies) — and records the
     * first-class reward_redemptions row plus the reward_redeemed /
     * leaderboard_updated frames, all committed atomically.
     *
     * @return array{ok: true, replay: bool, balance: int, ledger_id: int, redemption_id: int}
     *                                                                                         |array{ok: false, reason: 'insufficient'|'inactive'|'out_of_stock'|'duplicate', balance: int, shortfall?: int}
     */
    public function spendOnReward(Student $student, Reward $reward, ?string $requestId = null): array
    {
        // True idempotency FIRST — outside the write path: a replayed
        // request_id must return the original answer without touching
        // anything (the stock unit was already taken by that request).
        // Scoped to the calling student: a request_id minted by another
        // student's desk is never a replay of MINE (and must not leak
        // someone else's balance).
        if ($requestId !== null) {
            $existing = RewardRedemption::where('request_id', $requestId)
                ->where('student_id', $student->id)
                ->first();

            if ($existing !== null) {
                return [
                    'ok' => true,
                    'replay' => true,
                    'balance' => $this->balance($student),
                    'ledger_id' => $existing->ledger_id,
                    'redemption_id' => $existing->id,
                ];
            }
        }

        try {
            return DB::transaction(function () use ($student, $reward, $requestId) {
                // Re-read the balance inside the transaction to avoid a
                // double-spend race between two simultaneous desk redemptions.
                $balance = (int) PointsLedger::where('student_id', $student->id)
                    ->lockForUpdate()
                    ->sum('delta');

                if ($balance < $reward->point_cost) {
                    return [
                        'ok' => false,
                        'reason' => 'insufficient',
                        'balance' => $balance,
                        'shortfall' => $reward->point_cost - $balance,
                    ];
                }

                if (! $reward->active) {
                    return ['ok' => false, 'reason' => 'inactive', 'balance' => $balance];
                }

                // Limited stock: the atomic decrement IS the guard — two
                // concurrent redemption attempts cannot both take the last
                // unit (only one UPDATE matches stock > 0).
                if ($reward->stock !== null) {
                    $claimed = Reward::query()
                        ->whereKey($reward->id)
                        ->where('stock', '>', 0)
                        ->decrement('stock');

                    if ($claimed === 0) {
                        return ['ok' => false, 'reason' => 'out_of_stock', 'balance' => $balance];
                    }
                }

                // Double-submit protection (spec §20) — the FALLBACK guard:
                // only for clients that send NO idempotency key. A
                // request_id-bearing client already has exact protection;
                // distinct keys are intentional repeat purchases, and the
                // heuristic must never block those.
                $window = max(0, (int) config('recycling.redemption.duplicate_window_seconds'));
                if ($requestId === null && $window > 0) {
                    $recent = RewardRedemption::query()
                        ->where('student_id', $student->id)
                        ->where('reward_id', $reward->id)
                        ->where('created_at', '>=', now()->subSeconds($window))
                        ->exists();

                    if ($recent) {
                        // Undo the stock decrement — no side effects on a
                        // rejected duplicate.
                        if ($reward->stock !== null) {
                            Reward::query()->whereKey($reward->id)->increment('stock');
                        }

                        return ['ok' => false, 'reason' => 'duplicate', 'balance' => $balance];
                    }
                }

                $ledger = PointsLedger::create([
                    'student_id' => $student->id,
                    'delta' => -$reward->point_cost,
                    'reason' => 'redemption',
                    'reward_id' => $reward->id,
                ]);

                $redemption = RewardRedemption::create([
                    'reward_id' => $reward->id,
                    'student_id' => $student->id,
                    'ledger_id' => $ledger->id,
                    'points_spent' => $reward->point_cost,
                    'request_id' => $requestId,
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_REWARD_REDEEMED, [
                    'redemption_id' => $redemption->id,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'reward_id' => $reward->id,
                    'reward_name' => $reward->name,
                    'points_spent' => $reward->point_cost,
                    'new_balance' => $balance - $reward->point_cost,
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_LEADERBOARD_UPDATED, [
                    'top' => $this->leaderboard->snapshot(),
                ]);

                return [
                    'ok' => true,
                    'replay' => false,
                    'balance' => $balance - $reward->point_cost,
                    'ledger_id' => $ledger->id,
                    'redemption_id' => $redemption->id,
                ];
            });
        } catch (QueryException $e) {
            // The request_id UNIQUE index is the true concurrency guard:
            // two simultaneous submissions of the same key both pass the
            // pre-check, exactly one insert wins, the loser's transaction
            // rolls back (stock decrement included) and re-answers as a
            // replay of the winner — never a raw 500.
            if ($this->isUniqueViolation($e) && $requestId !== null) {
                $winner = RewardRedemption::where('request_id', $requestId)->first();

                if ($winner !== null && $winner->student_id === $student->id) {
                    return [
                        'ok' => true,
                        'replay' => true,
                        'balance' => $this->balance($student),
                        'ledger_id' => $winner->ledger_id,
                        'redemption_id' => $winner->id,
                    ];
                }

                // A key minted by another desk/student: not a replay of
                // this student's spend — reject without charging.
                return [
                    'ok' => false,
                    'reason' => 'duplicate',
                    'balance' => $this->balance($student),
                ];
            }

            throw $e;
        }
    }

    /**
     * Integrity-constraint detection across the supported drivers:
     * SQLite ("UNIQUE constraint failed"), MySQL ("Duplicate entry",
     * SQLSTATE 23000), PostgreSQL (SQLSTATE 23505).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || $sqlState === '23000'
            || $sqlState === '23505';
    }
}

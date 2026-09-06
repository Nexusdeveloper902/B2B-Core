<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use App\Services\PointsService;
use App\Services\Realtime\RecyclingUpdateLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Classification + points-earn orchestration (Phase C; TASK-025 items
 * 1/3/6/8 harden it).
 *
 * The MaterialClassifier contract is injected (bound to the configured
 * driver in AppServiceProvider) — tests swap the binding with a fake.
 *
 * Idempotency: one tap = one deposit = one award. A second classify
 * call for the same event_id returns the existing deposit without
 * awarding again (safe for device retries after network failures).
 *
 * TASK-025 item 1 — ATOMICITY: the deposit insert and the ledger insert
 * now share ONE transaction, so a crash between them can no longer
 * leave a deposit with no points (and a unique(event_id) index that
 * blocks the retry forever). The classifier call stays OUTSIDE the
 * transaction on purpose — never hold DB locks across a 15 s vision-API
 * round trip. The unique index remains the true concurrency guard: a
 * concurrent loser re-fetches the winner's deposit and answers with
 * the duplicate response instead of a raw 500 (graceful race handling).
 *
 * TASK-025 item 6 — every successful award records its realtime frames
 * (validated, points_awarded, leaderboard_updated) INSIDE the same
 * transaction, so the feed only ever broadcasts committed truth.
 */
class ClassificationService
{
    public function __construct(
        private readonly MaterialClassifier $classifier,
        private readonly PointsService $points,
        private readonly LeaderboardService $leaderboard,
    ) {}

    /**
     * @param  string  $imagePath  absolute filesystem path the classifier reads
     * @param  string|null  $storedPath  Storage-disk relative path persisted on the deposit (item 3)
     * @param  callable|null  $insideAwardTx  extra writes that must be atomic with the award (item 2:
     *                                        the bottle-first capture flip) — receives the fresh deposit
     * @return array{
     *   duplicate: bool,
     *   deposit: RecyclingDeposit,
     *   new_balance: int
     * }
     */
    public function classifyAndAward(
        Reader $reader,
        PresenceEvent $event,
        string $imagePath,
        ?string $storedPath = null,
        ?callable $insideAwardTx = null,
    ): array {
        $student = $event->card->student;

        // Fast path: already classified — no classifier call, no writes.
        $existing = RecyclingDeposit::where('event_id', $event->id)->first();

        if ($existing !== null) {
            return [
                'duplicate' => true,
                'deposit' => $existing,
                'new_balance' => $this->points->balance($student),
            ];
        }

        // The (potentially slow, network-bound) judgment runs OUTSIDE any
        // transaction — see the class doc.
        $result = $this->classifier->classify($imagePath);
        $material = MaterialClass::from($result['material_class']);

        try {
            return DB::transaction(function () use ($event, $student, $material, $result, $storedPath, $insideAwardTx) {
                // Re-check under the transaction: the unique(event_id)
                // index is the real guard; this makes the common loser
                // graceful (duplicate response, not a constraint 500).
                $existing = RecyclingDeposit::where('event_id', $event->id)->lockForUpdate()->first();

                if ($existing !== null) {
                    return [
                        'duplicate' => true,
                        'deposit' => $existing,
                        'new_balance' => $this->points->balance($student),
                    ];
                }

                $deposit = RecyclingDeposit::create([
                    'event_id' => $event->id,
                    'image_path' => $storedPath,
                    'material_class' => $material->value,
                    'confidence' => (float) $result['confidence'],
                    'points_awarded' => $material->points(),
                    'is_bottle' => (bool) ($result['is_bottle'] ?? false),
                    'is_recyclable' => (bool) ($result['is_recyclable'] ?? false),
                ]);

                $this->points->awardRecyclingPoints($student, $event, $material);

                $newBalance = $this->points->balance($student);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_VALIDATED, [
                    'event_id' => $event->id,
                    'deposit_id' => $deposit->id,
                    'material_class' => $material->value,
                    'confidence' => (float) $result['confidence'],
                    'is_bottle' => (bool) ($result['is_bottle'] ?? false),
                    'is_recyclable' => (bool) ($result['is_recyclable'] ?? false),
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_POINTS_AWARDED, [
                    'event_id' => $event->id,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'material_class' => $material->value,
                    'points' => $material->points(),
                    'new_balance' => $newBalance,
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_LEADERBOARD_UPDATED, [
                    'top' => $this->leaderboard->snapshot(),
                ]);

                if ($insideAwardTx !== null) {
                    $insideAwardTx($deposit);
                }

                return [
                    'duplicate' => false,
                    'deposit' => $deposit->refresh(),
                    'new_balance' => $newBalance,
                ];
            });
        } catch (QueryException $e) {
            // A concurrent winner inserted the deposit between our
            // pre-check and ours — answer as the duplicate, exactly like
            // the friendly path (TASK-025 item 1: no raw 500 races).
            if ($this->isUniqueViolation($e)) {
                $winner = RecyclingDeposit::where('event_id', $event->id)->firstOrFail();

                return [
                    'duplicate' => true,
                    'deposit' => $winner,
                    'new_balance' => $this->points->balance($student),
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

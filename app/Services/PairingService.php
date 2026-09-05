<?php

namespace App\Services;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\Reader;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-010 — card pairing (two-step arm-then-pair; ADR-020).
 *
 * Works identically whether the pair call comes from Postman, a test, or
 * the ESP32 reader firmware (Bearer reader key — the Hardware Abstraction
 * Principle): arming happens on the dashboard side, pairing happens on
 * the device side, and the short window is the synchronization between
 * the two.
 */
class PairingService
{
    public function __construct(
        private readonly int $windowSeconds,
    ) {}

    /**
     * Arm a pending pairing for a student: the next fresh card scanned
     * within the window will be linked to them.
     */
    public function arm(Student $student): PendingPairing
    {
        return PendingPairing::create([
            'student_id' => $student->id,
            'expires_at' => now()->addSeconds($this->windowSeconds),
        ]);
    }

    /**
     * TASK-011 — the currently active pending pairing (the one the next
     * fresh card scan would consume), or null when nothing is armed.
     * Same ordering rule as pair(): most recent armed wins.
     */
    public function activeSession(): ?PendingPairing
    {
        return PendingPairing::active()
            ->orderByDesc('id')
            ->with('student')
            ->first();
    }

    /**
     * TASK-011 — completed pairings (consumed + card stamped), newest
     * first, eager-loaded for the pairing desk's history list.
     *
     * @return Collection<int, PendingPairing>
     */
    public function recentCompletions(int $limit = 8): Collection
    {
        return PendingPairing::whereNotNull('consumed_at')
            ->whereNotNull('card_id')
            ->with(['student', 'card', 'reader'])
            ->orderByDesc('consumed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Pair a scanned credential with the most recent active pending
     * pairing.
     *
     * @return array{ok: true, card: Card, student: Student, pairing: PendingPairing}
     *                                                                                | array{ok: false, reason: 'no_session'|'already_paired'}
     */
    public function pair(Reader $reader, string $credentialUid): array
    {
        return DB::transaction(function () use ($reader, $credentialUid) {
            // Lock the candidate row so two simultaneous pair taps cannot
            // both consume the same pending session (same convention as
            // the redemption row-lock in PointsService).
            /** @var PendingPairing|null $pairing */
            $pairing = PendingPairing::active()
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($pairing === null) {
                return ['ok' => false, 'reason' => 'no_session'];
            }

            // Never silently reassign an existing card to a new student:
            // any credential_uid that already has a cards row is rejected,
            // whatever its status (a replacement card is a NEW credential).
            $existing = Card::where('credential_uid', $credentialUid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['ok' => false, 'reason' => 'already_paired'];
            }

            $card = Card::create([
                'credential_uid' => $credentialUid,
                'student_id' => $pairing->student_id,
            ]);

            $pairing->update([
                'consumed_at' => now(),
                'reader_id' => $reader->id,
                'card_id' => $card->id, // TASK-011: audit trail for the pairing desk
            ]);

            return [
                'ok' => true,
                'card' => $card,
                'student' => $pairing->student,
                'pairing' => $pairing,
            ];
        });
    }
}

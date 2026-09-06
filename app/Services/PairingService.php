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
     *
     * TASK-020 — one armed window at a time, by construction: arming
     * closes every other still-active window first. Before this, a
     * double-arm (impatient double-click, a re-arm from another tab)
     * left the superseded row live; when the newer row was consumed by
     * the card tap, that zombie resurfaced as "the" active window — the
     * desk kept counting down and NEVER showed the pairing that had
     * just succeeded (the exact "first try is broken, retry is fine"
     * bench race). Newest-wins was already the lookup rule in pair();
     * now the invariant is enforced at write time too, so "newest
     * active" and "the window" are the same row — always.
     */
    public function arm(Student $student): PendingPairing
    {
        return DB::transaction(function () use ($student) {
            PendingPairing::active()
                ->update(['expires_at' => now()]);

            return PendingPairing::create([
                'student_id' => $student->id,
                'expires_at' => now()->addSeconds($this->windowSeconds),
            ]);
        });
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
     * TASK-020 — the pairing status payload, built from ONE place: the
     * REST status endpoint (PairingStatusController) and the realtime
     * pairing frames (RealtimePairing) both serialize this exact array,
     * so the desk's poll path and its WebSocket path can never disagree
     * about the state of the world.
     *
     * @return array{pending: array<string, mixed>|null, last_pairing: array<string, mixed>|null, recent_pairings: array<int, array<string, mixed>>}
     */
    public function statusPayload(): array
    {
        $active = $this->activeSession();
        $recent = $this->recentCompletions(8);

        return [
            'pending' => $active !== null ? [
                'student_id' => $active->student_id,
                'student_name' => $active->student?->name,
                'expires_at' => $active->expires_at->toIso8601String(),
                'seconds_left' => max(0, (int) now()->diffInSeconds($active->expires_at)),
                // TASK-014 — the latest REJECTED tap on this armed window
                // (422 already_paired): the desk shows it with the fresh-card /
                // ./run unpair remediation instead of counting down in silence.
                'last_rejection' => $active->last_rejected_uid !== null ? [
                    'card_uid' => $active->last_rejected_uid,
                    'reason' => $active->last_rejected_reason,
                    'at' => $active->last_rejected_at?->toIso8601String(),
                ] : null,
            ] : null,
            'last_pairing' => $recent->isNotEmpty() ? [
                'card_uid' => $recent->first()->card?->credential_uid,
                'student_name' => $recent->first()->student?->name,
                'paired_at' => $recent->first()->consumed_at?->toIso8601String(),
                'reader_label' => $recent->first()->reader?->label,
            ] : null,
            'recent_pairings' => $recent->map(fn ($p) => [
                'card_uid' => $p->card?->credential_uid,
                'student_name' => $p->student?->name,
                'paired_at' => $p->consumed_at?->toIso8601String(),
                'reader_label' => $p->reader?->label,
            ])->values()->all(),
        ];
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
                // TASK-014 — the rejection must be VISIBLE at the desk:
                // stamp it on the armed window that witnessed it (the
                // device gets the 422 JSON; without this, the operator
                // saw only a countdown and then "window expired").
                $pairing->update([
                    'last_rejected_uid' => $credentialUid,
                    'last_rejected_reason' => 'already_paired',
                    'last_rejected_at' => now(),
                ]);

                return ['ok' => false, 'reason' => 'already_paired'];
            }

            $card = Card::create([
                'credential_uid' => $credentialUid,
                'student_id' => $pairing->student_id,
            ]);

            // TASK-020 — a consumed window closes the whole loop: any
            // older still-active row (pre-invariant data) is retired here
            // too, so a success can never be shadowed by a stale window.
            PendingPairing::whereNull('consumed_at')
                ->where('id', '!=', $pairing->id)
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

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

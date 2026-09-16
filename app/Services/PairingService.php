<?php

namespace App\Services;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\Reader;
use App\Models\Student;
use App\Services\Hce\HceCredentialAuth;
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
        private readonly ?int $windowSeconds = null,
    ) {}

    /**
     * TASK-037 — the window is runtime-configurable (ADR-055): admins
     * tune it in /admin settings instead of .env. Resolved per call so
     * a settings change is live for the very next arm, without process
     * restarts; the constructor value stays as the legacy override seam.
     */
    private function window(): int
    {
        return $this->windowSeconds ?? settings()->pairingWindowSeconds();
    }

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
                'expires_at' => now()->addSeconds($this->window()),
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
                // HCE integration: HOW the credential was captured
                // (physical | hce) — the pairing desk badges phone
                // credentials; the realtime pairing channel carries the
                // same payload, so no WS contract change was needed.
                'card_kind' => $recent->first()->card?->kind?->value ?? 'physical',
                'student_id' => $recent->first()->student_id,
                'student_name' => $recent->first()->student?->name,
                'paired_at' => $recent->first()->consumed_at?->toIso8601String(),
                'reader_label' => $recent->first()->reader?->label,
            ] : null,
            'recent_pairings' => $recent->map(fn ($p) => [
                'card_uid' => $p->card?->credential_uid,
                'card_kind' => $p->card?->kind?->value ?? 'physical',
                'student_id' => $p->student_id,
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
     * $kind records HOW the credential was captured ('physical' for an
     * RF-layer MIFARE UID, 'hce' for an application-level Android HCE
     * credential id from the SELECT AID + CHALLENGE exchange). It is
     * display/audit metadata only: the tap lookup stays
     * credential_uid-only, so a kind mismatch can never break
     * identification — and old readers that omit the kind pair exactly
     * as before.
     *
     * TASK-049 (ADR-068) — an `hce` pairing is also the key hand-off:
     * $hce carries the relayed CHALLENGE proof (hce_nonce/hce_mac) and the
     * reader-wrapped key (hce_key_wrapped/hce_key_nonce). The key is
     * accepted only if the proof verifies under it. A credential id that
     * is already paired may be RE-KEYED only when it is an active phone
     * credential of the very student this window was armed for (a
     * reinstall wipes the Keystore; history survives) — any other
     * existing row stays `already_paired`, so provisioning can never
     * overwrite another credential's key.
     *
     * @param  array<string, mixed>  $hce
     * @return array{ok: true, card: Card, student: Student, pairing: PendingPairing, rekeyed: bool}
     *                                                                                               | array{ok: false, reason: 'no_session'|'already_paired'|'hce_proof_invalid'}
     */
    public function pair(Reader $reader, string $credentialUid, string $kind = 'physical', array $hce = []): array
    {
        $kind = $kind === 'hce' ? 'hce' : 'physical';

        return DB::transaction(function () use ($reader, $credentialUid, $kind, $hce) {
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

            $key = null;
            if ($kind === 'hce') {
                $accepted = app(HceCredentialAuth::class)->acceptProvisioning($reader, $credentialUid, $hce);

                if (! $accepted['ok']) {
                    $pairing->update([
                        'last_rejected_uid' => $credentialUid,
                        'last_rejected_reason' => 'hce_proof_invalid',
                        'last_rejected_at' => now(),
                    ]);

                    return ['ok' => false, 'reason' => 'hce_proof_invalid'];
                }

                $key = $accepted['key'];

                $rekey = $existing !== null
                    && $existing->isHce()
                    && $existing->isActive()
                    && (int) $existing->student_id === (int) $pairing->student_id;

                if ($rekey) {
                    app(HceCredentialAuth::class)->storeKey($existing, $key, $reader);

                    return $this->consume($pairing, $reader, $existing, rekeyed: true);
                }
            }

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
                'kind' => $kind,
                'student_id' => $pairing->student_id,
            ]);

            if ($key !== null) {
                app(HceCredentialAuth::class)->storeKey($card, $key, $reader);
            }

            return $this->consume($pairing, $reader, $card, rekeyed: false);
        });
    }

    /**
     * Close the armed window on a successful pair (or re-key).
     *
     * @return array{ok: true, card: Card, student: Student, pairing: PendingPairing, rekeyed: bool}
     */
    private function consume(PendingPairing $pairing, Reader $reader, Card $card, bool $rekeyed): array
    {
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
            'rekeyed' => $rekeyed,
        ];
    }
}

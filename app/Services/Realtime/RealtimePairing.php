<?php

namespace App\Services\Realtime;

use App\Services\PairingService;
use Illuminate\Support\Facades\DB;

/**
 * The pairing channel behind the realtime feed (TASK-020, ADR-029).
 *
 * Reads ONLY — same decoupling rule as RealtimeFeed: the
 * `pending_pairings` table stays the single source of truth, and every
 * process sharing the database (the desk's arm endpoint, the reader's
 * pair endpoint, a test) is a broadcaster. `realtime:serve` polls a
 * cheap signature of the relevant rows; when it changes, the pairing
 * desk gets a `pairing` frame with the same payload the REST status
 * endpoint serves (PairingService::statusPayload() — one truth).
 *
 * The signature deliberately hashes the ROW data, not the rendered
 * payload: time-only transitions (a window counting down, expiring)
 * are not row changes. The desk's own client-side countdown owns the
 * passing seconds; its poll (the honest fallback when the socket is
 * down) and this channel own the STATE changes — armed, consumed,
 * rejected.
 */
final class RealtimePairing
{
    /**
     * Rows whose changes matter to the desk: the recent completions
     * (history) plus any unconsumed row (the armed window, whatever
     * its expiry — a rejection stamp mutates an armed row).
     */
    private const SIGNATURE_ROWS = 12;

    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    /**
     * A cheap, complete fingerprint of the pairing state's inputs:
     * md5 over the mutable columns of the rows the desk renders.
     * Arm, consume and reject all change it; a pure countdown tick
     * does not (by design — see the class doc).
     */
    public function signature(): string
    {
        try {
            $rows = DB::table('pending_pairings')
                ->orderByDesc('id')
                ->limit(self::SIGNATURE_ROWS)
                ->get([
                    'id', 'student_id', 'reader_id', 'card_id',
                    'expires_at', 'consumed_at',
                    'last_rejected_uid', 'last_rejected_reason', 'last_rejected_at',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            return md5(json_encode($rows));
        } catch (\Throwable) {
            // DB mid-replacement (./run reset): keep the last known
            // signature — the poll caller also purges and retries, and
            // a quiet beat beats a dead feed (same rule as RealtimeFeed).
            return '';
        }
    }

    /**
     * The broadcast payload — PairingService::statusPayload() verbatim,
     * so the WebSocket path and the REST status path can never disagree.
     *
     * @return array{pending: array<string, mixed>|null, last_pairing: array<string, mixed>|null, recent_pairings: array<int, array<string, mixed>>}
     */
    public function payload(): array
    {
        return $this->pairings->statusPayload();
    }
}

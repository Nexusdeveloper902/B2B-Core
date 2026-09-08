<?php

namespace App\Services\Realtime;

use App\Models\RosterUpdate;

/**
 * TASK-029 — the WRITE half of the roster channel.
 *
 * Every call MUST run inside the same DB transaction as the state
 * change it describes — that is the entire safety argument (same as
 * RecyclingUpdateLog): rows only become visible when the transaction
 * commits, so realtime:serve can never broadcast pre-commit state.
 */
final class RosterUpdateLog
{
    /**
     * @param  array<string, mixed>  $payload  precomputed frame payload (the writer owns the context)
     */
    public static function record(string $type, array $payload): RosterUpdate
    {
        return RosterUpdate::create([
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}

<?php

namespace App\Services\Realtime;

use App\Models\RecyclingUpdate;

/**
 * TASK-025 item 6 — the WRITE half of the recycling channel.
 *
 * Every call MUST run inside the same DB transaction as the state
 * change it describes — that is the entire safety argument: rows only
 * become visible when the transaction commits, so realtime:serve can
 * never broadcast pre-commit state (spec §27: "push only from committed
 * database state").
 */
final class RecyclingUpdateLog
{
    /**
     * @param  array<string, mixed>  $payload  precomputed frame payload (the writer owns the context)
     */
    public static function record(string $type, array $payload): RecyclingUpdate
    {
        return RecyclingUpdate::create([
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}

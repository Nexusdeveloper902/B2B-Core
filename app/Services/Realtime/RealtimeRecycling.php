<?php

namespace App\Services\Realtime;

use App\Support\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * TASK-025 item 6 — the READ half of the recycling channel.
 *
 * Same decoupling rule as RealtimeFeed/RealtimePairing: the
 * `recycling_updates` table (written transactionally by whatever
 * process performs the change) is the single broadcast source;
 * realtime:serve polls rows newer than its head and pushes them as
 * `recycling` frames. Reads ONLY — the socket server never mutates.
 */
final class RealtimeRecycling
{
    /**
     * Updates with id > $updateId, oldest first (the broadcast delta).
     *
     * @return array<int, array<string, mixed>>
     */
    public function updatesAfter(int $updateId, int $limit = 100): array
    {
        return $this->rows($limit, 'asc', false, $updateId);
    }

    /**
     * The most recent updates, oldest first — the hello-frame snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        return $this->rows($limit, 'desc', true, null);
    }

    public function latestUpdateId(): int
    {
        try {
            return (int) (DB::table('recycling_updates')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $limit, string $direction, bool $reverse, ?int $afterId): array
    {
        $query = DB::table('recycling_updates');

        // TASK-045 (ADR-064) — same wall as the tap channel: scoped in
        // the web tier, per-connection inside realtime:serve.
        app(CurrentSchool::class)->applyTo($query, 'school_id');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query
            ->orderBy('id', $direction)
            ->limit($limit)
            ->get(['id', 'type', 'payload', 'school_id', 'created_at'])
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'payload' => is_array($payload) ? $payload : [],
                    'school_id' => $row->school_id !== null ? (int) $row->school_id : null,
                    'at' => (string) $row->created_at,
                ];
            })
            ->all();

        return $reverse ? \array_reverse($rows) : $rows;
    }
}

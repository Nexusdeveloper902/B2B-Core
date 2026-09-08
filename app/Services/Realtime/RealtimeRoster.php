<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * TASK-029 — the READ half of the roster channel.
 *
 * Same decoupling rule as RealtimeFeed/RealtimePairing/RealtimeRecycling:
 * the append-only `roster_updates` table (written transactionally by
 * whatever process performs the change) is the single broadcast source;
 * realtime:serve polls rows newer than its head and pushes them as
 * `roster` frames. Reads ONLY — the socket server never mutates.
 */
final class RealtimeRoster
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
     * The most recent updates, oldest first — the hello-frame snapshot
     * (a freshly connected admin page reconciles anything that changed
     * between its SSR paint and the WS connect; handlers are
     * update-or-prepend, so replay is safe).
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
            return (int) (DB::table('roster_updates')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $limit, string $direction, bool $reverse, ?int $afterId): array
    {
        $query = DB::table('roster_updates');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query
            ->orderBy('id', $direction)
            ->limit($limit)
            ->get(['id', 'type', 'payload', 'created_at'])
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'payload' => is_array($payload) ? $payload : [],
                    'at' => (string) $row->created_at,
                ];
            })
            ->all();

        return $reverse ? \array_reverse($rows) : $rows;
    }
}

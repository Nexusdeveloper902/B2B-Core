<?php

namespace App\Services\Realtime;

use App\Models\Reader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-047 — the `feedback` channel: taps that were answered but wrote
 * no events row (duplicate / unknown card / inactive card).
 *
 * Write half: TapService records one row per such tap (inside the tap's
 * transaction where there is one). Read half: realtime:serve polls rows
 * newer than its head and pushes them as `feedback` frames. Taps that
 * write a row are NOT logged here — the `tap` frame already carries
 * their cue — so a feedback device never hears one tap twice.
 */
final class TapFeedback
{
    /**
     * Best-effort: a speaker cue must never fail the device's tap (the
     * device answer is the product; the beep is a nicety). A failed write
     * is logged and swallowed — MariaDB and SQLite keep the surrounding
     * transaction usable after a failed statement.
     */
    public static function record(Reader $reader, string $cue, string $reason, ?int $eventId = null): void
    {
        try {
            DB::table('tap_feedback')->insert([
                'school_id' => $reader->school_id,
                'reader_id' => $reader->id,
                'event_id' => $eventId,
                'cue' => $cue,
                'reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('tap_feedback cue not recorded: '.$e->getMessage());
        }
    }

    /**
     * Rows with id > $afterId, oldest first (the broadcast delta).
     *
     * @return array<int, array{id: int, cue: string, reason: string, event_id: int|null, school_id: int|null}>
     */
    public function after(int $afterId, int $limit = 100): array
    {
        return DB::table('tap_feedback')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'cue', 'reason', 'event_id', 'school_id'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'cue' => (string) $row->cue,
                'reason' => (string) $row->reason,
                'event_id' => $row->event_id !== null ? (int) $row->event_id : null,
                'school_id' => $row->school_id !== null ? (int) $row->school_id : null,
            ])
            ->all();
    }

    public function latestId(): int
    {
        try {
            return (int) (DB::table('tap_feedback')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}

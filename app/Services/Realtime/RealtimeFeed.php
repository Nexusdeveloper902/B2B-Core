<?php

namespace App\Services\Realtime;

use App\Support\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * The tap-event feed behind the realtime channel (TASK-016, ADR-026).
 *
 * Reads ONLY — the `events` table stays the single source of truth:
 * the same rows the dashboards query server-side are the rows the
 * WebSocket server polls and pushes. Card/student joins resolve the
 * readable context; cards cascade-delete their events, so an inner
 * join can never orphan a feed row.
 */
final class RealtimeFeed
{
    /**
     * Events with id > $eventId, oldest first (the broadcast delta).
     *
     * @param  array<int, int>|null  $classIds  TASK-027 — scope filter (teacher SSR feed); null = school-wide
     * @return array<int, array<string, mixed>>
     */
    public function eventsAfter(int $eventId, int $limit = 100, ?array $classIds = null): array
    {
        return $this->rows($limit, 'asc', false, $eventId, $classIds);
    }

    /**
     * The most recent events, oldest first — the initial feed state
     * sent in the hello frame and server-rendered into the page.
     *
     * @param  array<int, int>|null  $classIds  TASK-027 — scope filter (teacher SSR feed); null = school-wide
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20, ?array $classIds = null): array
    {
        return $this->rows($limit, 'desc', true, null, $classIds);
    }

    public function latestEventId(): int
    {
        try {
            return (int) (DB::table('events')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int, int>|null  $classIds
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $limit, string $direction, bool $reverse, ?int $afterId, ?array $classIds): array
    {
        $query = DB::table('events')
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->leftJoin('readers', 'readers.id', '=', 'events.reader_id');

        // TASK-045 (ADR-064) — the organization wall on a raw builder.
        // In the web tier this scopes the SSR feed to the viewer's own
        // school; inside realtime:serve there is no request identity, so
        // it is a no-op and the per-CONNECTION filter owns the wall.
        app(CurrentSchool::class)->applyTo($query, 'events.school_id');

        if ($afterId !== null) {
            $query->where('events.id', '>', $afterId);
        }

        if ($classIds !== null) {
            // TASK-027 — the teacher data wall: a scoped feed shows only
            // the classes the teacher teaches (school-wide stays null).
            $query->whereIn('students.class_id', $classIds);
        }

        $rows = $query
            ->orderBy('events.id', $direction)
            ->limit($limit)
            ->get([
                'events.id',
                'events.type',
                'events.occurred_at',
                'events.served',
                'events.reason',
                'events.school_id',
                'students.id as student_id',
                'students.name as student_name',
                'students.class_id as class_id',
                'classes.name as class_name',
                'readers.label as reader_label',
            ])
            ->map(function ($row) {
                // occurred_at is stored as naive wall time in the app
                // timezone (TASK-015) — the H:i the feed shows is the
                // same school-local clock every dashboard renders.
                $occurredAt = (string) $row->occurred_at;

                return [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    // TASK-037 — meal semantics on the wire: served=false
                    // + reason marks a flagged/excluded attempt; the
                    // kitchen page colors its big state from these and
                    // the dashboards style flagged chips differently.
                    'served' => (bool) $row->served,
                    // TASK-045 — the owning organization, so the socket
                    // server can refuse a frame per connection.
                    'school_id' => $row->school_id !== null ? (int) $row->school_id : null,
                    'reason' => $row->reason !== null ? (string) $row->reason : null,
                    // TASK-047 — the audible cue a feedback device (the
                    // Android bridge) plays for this row. Derived from
                    // `served` — the same accept/reject truth the kitchen
                    // desk colors from — so no second event stream exists.
                    'feedback' => $row->served ? 'accepted' : 'rejected',
                    'student_id' => (int) $row->student_id,
                    'student_name' => (string) $row->student_name,
                    // TASK-027 — class_id lets the realtime server apply
                    // the per-connection teacher/student scope before a
                    // tap frame crosses the wire (same exposure level as
                    // class_name, which the payload already carries).
                    'class_id' => $row->class_id !== null ? (int) $row->class_id : null,
                    'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
                    'reader_label' => $row->reader_label !== null ? (string) $row->reader_label : null,
                    'time' => substr($occurredAt, 11, 5),
                    'date' => substr($occurredAt, 0, 10),
                ];
            })
            ->all();

        return $reverse ? \array_reverse($rows) : $rows;
    }
}

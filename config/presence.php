<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Presence Platform
    |--------------------------------------------------------------------------
    |
    | Core configuration for the presence-event model: a single tap becomes a
    | labeled event, and attendance / PAE / recycling are all derived views
    | over the same `events` table (the "event-type spine").
    |
    */

    /*
     | The known set of event types. This is the single source of truth for:
     | - readers.active_event_type validation
     | - events.type values
     | - dashboard derivations
     |
     | Mirrored by App\Enums\EventType (the enum is canonical in code; this
     | config listing exists for documentation and external tooling).
     */
    'event_types' => [
        'CLASS_ATTENDANCE',
        'PAE_BREAKFAST',
        'PAE_LUNCH',
        'RECYCLING_DEPOSIT',
        'ENTRY',
    ],

    /*
     | Readers of type `classroom` may be relabeled between these modes.
     | Currently any known event type is a valid mode for any reader (the
     | relabeling feature is the point); tighten per-type here if the school
     | ever wants stricter rules.
     */
    'reader_types' => [
        'classroom',
        'pae',
        'recycling',
        'entry',
    ],

    /*
     | TASK-010 — card pairing window. How long an armed pending pairing
     | stays active before the next card scan can consume it (seconds).
     | 45 s default: long enough for the operator to walk to the reader
     | and tap a fresh card; short enough to not leave stray open
     | sessions. ADR-020.
     */
    'pairing_window_seconds' => env('PAIRING_WINDOW_SECONDS', 45),

    /*
     | A tap that happens after this local time counts as "late" on the
     | teacher dashboard. Simple constant cutoff by design — not a full
     | policy engine. Format: "HH:MM" (24h, school local time).
     */
    'late_cutoff' => env('ATTENDANCE_LATE_CUTOFF', '08:15'),

    /*
     | Supported application/UI locales (English and Spanish).
     */
    'locales' => ['en', 'es'],

];

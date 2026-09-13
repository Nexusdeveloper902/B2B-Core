<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pulse (presence-event core)
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
     |
     | TASK-037 — ENTRY/EXIT removed (supersedes ADR-038); PAE_ATTEMPT is
     | engine-written only (flagged out-of-window rows — never a reader
     | mode; see EventType::validReaderModes()).
     */
    'event_types' => [
        'CLASS_ATTENDANCE',
        'PAE_BREAKFAST',
        'PAE_LUNCH',
        'RECYCLING_DEPOSIT',
        'PAE_ATTEMPT',
    ],

    /*
     | Readers of type `classroom` may be relabeled between these modes.
     | TASK-037: `entry` removed with the ENTRY/EXIT workflow.
     */
    'reader_types' => [
        'classroom',
        'pae',
        'recycling',
    ],

    /*
     | TASK-037 — PAE meal serving windows (ADR-053). School-local wall
     | time (America/Bogota, no DST — ADR-025). These are the CONFIG
     | DEFAULTS: admins override them at runtime through /admin/settings
     | (settings table, ADR-055); the serving engine never reads these
     | values directly — it goes through SettingsService.
     |
     | Format "HH:MM", 24h. Window boundaries: start inclusive, end
     | exclusive. Breakfast/lunch windows must not overlap (validated on
     | save; the engine flags any residual overlap as window_overlap).
     */
    'pae' => [
        'breakfast_start' => env('PAE_BREAKFAST_START', '06:30'),
        'breakfast_end' => env('PAE_BREAKFAST_END', '08:30'),
        'lunch_start' => env('PAE_LUNCH_START', '11:30'),
        'lunch_end' => env('PAE_LUNCH_END', '13:30'),
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

    /*
     | TASK-030-A (ADR-044) — automatic student account provisioning.
     | New enrollments mint a 1:1 login on the established convention
     | ({first-name}@domain, DemoSeeder's pattern) with this shared
     | initial password; the account is forced to rotate it on first
     | login (users.must_change_password), so the shared value never
     | lingers. Rotation enforcement — not password entropy — is the
     | control at this scale.
     */
    'student_email_domain' => env('STUDENT_EMAIL_DOMAIN', 'presence.test'),
    'student_initial_password' => env('STUDENT_INITIAL_PASSWORD', 'password'),

];

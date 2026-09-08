<?php

namespace App\Enums;

use Illuminate\Support\Collection;

/**
 * The canonical set of event types — the "event-type spine".
 *
 * A single tap at a reader becomes one events row whose `type` is the
 * reader's active mode at tap time. Attendance, PAE and recycling reports
 * are all derived views over this one column; nothing stores "attendance"
 * separately.
 */
enum EventType: string
{
    case ClassAttendance = 'CLASS_ATTENDANCE';
    case PaeBreakfast = 'PAE_BREAKFAST';
    case PaeLunch = 'PAE_LUNCH';
    case RecyclingDeposit = 'RECYCLING_DEPOSIT';
    case Entry = 'ENTRY';

    /**
     * TASK-027 — the EXIT half of the entry/exit pair. An entry reader at
     * the school gate logs ENTRY on the way in and EXIT on the way out;
     * multiple rows per student per day are the point (every entry is
     * registered), and AttendanceService::studentSessions() pairs them
     * on the fly to derive time-in-school. No schema change: the value
     * rides the same events.type spine.
     */
    case Departure = 'EXIT';

    /** @return Collection<int, string> */
    public static function values(): Collection
    {
        return collect(self::cases())->map(fn (self $case) => $case->value);
    }
}

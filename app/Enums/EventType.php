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

    /** @return Collection<int, string> */
    public static function values(): Collection
    {
        return collect(self::cases())->map(fn (self $case) => $case->value);
    }
}

<?php

namespace App\Enums;

use Illuminate\Support\Collection;

/**
 * The canonical set of event types — the "event-type spine".
 *
 * A tap at a reader becomes one events row whose `type` is derived from
 * the reader's role at tap time. Attendance, PAE and recycling reports
 * are all derived views over this one column; nothing stores "attendance"
 * separately.
 *
 * TASK-037 — PAE meal rows are written by the MealServingEngine (auto
 * meal detection from the configured serving windows), not by a manual
 * reader mode: PAE_BREAKFAST/PAE_LUNCH mean a meal window was active, the
 * student was enrolled, had prior same-day attendance and had not yet
 * received that meal (served=true). Rejected attempts keep the attempted
 * meal's type with served=false + reason, or PAE_ATTEMPT when no meal
 * window was active at all (out-of-window / weekend taps).
 *
 * TASK-037 — ENTRY and EXIT are REMOVED from the platform (supersedes
 * ADR-038): the entry/exit session feature is not part of the target PAE
 * workflow, and the attendance prerequisite now relies exclusively on
 * CLASS_ATTENDANCE.
 */
enum EventType: string
{
    case ClassAttendance = 'CLASS_ATTENDANCE';
    case PaeBreakfast = 'PAE_BREAKFAST';
    case PaeLunch = 'PAE_LUNCH';
    case RecyclingDeposit = 'RECYCLING_DEPOSIT';

    /**
     * TASK-037 — a flagged-only type: a PAE tap where NO meal window was
     * active (out-of-window or weekend). Rows of this type are always
     * served=false and never a valid reader mode — see validReaderModes().
     */
    case PaeAttempt = 'PAE_ATTEMPT';

    /** @return Collection<int, string> */
    public static function values(): Collection
    {
        return collect(self::cases())->map(fn (self $case) => $case->value);
    }

    /**
     * The event types a reader may be relabeled to. PAE_ATTEMPT is a
     * flagged-audit-only value written by the serving engine — it can
     * never be assigned as a mode.
     *
     * @return Collection<int, string>
     */
    public static function validReaderModes(): Collection
    {
        return collect([
            self::ClassAttendance,
            self::PaeBreakfast,
            self::PaeLunch,
            self::RecyclingDeposit,
        ])->map(fn (self $case) => $case->value);
    }

    /** True for every PAE-related type (served meals and flagged attempts). */
    public function isMealRelated(): bool
    {
        return $this === self::PaeBreakfast || $this === self::PaeLunch || $this === self::PaeAttempt;
    }
}

<?php

namespace App\Services;

use App\Enums\ReaderType;
use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-037 — the PAE meal-serving engine (ADR-053).
 *
 * A tap at a meal reader (type `pae`, or any reader whose mode is a PAE
 * meal) is evaluated against the complete serving rule set BEFORE any
 * events row is committed:
 *
 *   1. School day   — Monday–Friday, school-local time (America/Bogota,
 *                     no DST — ADR-025). Weekend taps are flagged, not served.
 *   2. Active meal  — auto-detected from the admin-configurable serving
 *                     windows (SettingsService). No manual reader meal mode.
 *                     Exactly one window must be active; a configured
 *                     overlap is flagged loudly (window_overlap), never
 *                     silently assigned.
 *   3. Enrollment   — the student must be enrolled for the SPECIFIC meal
 *                     (breakfast and lunch are independent flags).
 *   4. Attendance   — a strictly earlier CLASS_ATTENDANCE event on the
 *                     same school-local day (ENTRY is gone — the class
 *                     tap is the only presence prerequisite).
 *   5. Duplicate    — one meal of each kind per student per day.
 *
 * All five pass → one served meal row (served=true, reason=null) that
 * counts toward PAE statistics. Any failure → a FLAGGED row (served=false
 * + machine-stable reason) that stays auditable in feeds/lists/reports
 * but never inflates served-meal metrics. The original valid meal row is
 * never altered by a later duplicate attempt.
 *
 * Concurrency: the whole evaluation + insert runs inside a transaction
 * holding a row lock on the tapped card — two simultaneous taps by the
 * same student serialize; the loser becomes the honest duplicate (same
 * convention as PairingService::pair and the redemption row-lock).
 */
class MealServingService
{
    /** Machine-stable rejection reasons (reported + persisted verbatim). */
    public const REASON_WEEKEND = 'weekend';

    public const REASON_OUT_OF_WINDOW = 'out_of_window';

    public const REASON_WINDOW_OVERLAP = 'window_overlap';

    public const REASON_NO_STUDENT = 'no_student';

    public const REASON_NOT_ENROLLED = 'not_enrolled';

    public const REASON_NO_ATTENDANCE = 'no_attendance';

    public const REASON_DUPLICATE = 'duplicate';

    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Does this tap belong to the meal-serving engine?
     *
     * A `pae`-type reader is a cafeteria reader — always engine territory
     * (its active_event_type label is not the meal authority). A reader
     * relabeled into a PAE mode (the legacy manual workflow) also routes
     * here: the engine auto-detects the meal from the clock, so the label
     * becomes a hint, never the truth.
     */
    public function isMealReader(Reader $reader): bool
    {
        return $reader->type === ReaderType::Pae
            || in_array($reader->active_event_type, ['PAE_BREAKFAST', 'PAE_LUNCH'], true);
    }

    /**
     * The meal whose serving window is active at $at, if exactly one.
     *
     * @return string|null 'breakfast'|'lunch'|null
     */
    public function activeMeal(Carbon $at): ?string
    {
        $active = $this->activeMeals($at);

        return count($active) === 1 ? $active[0] : null;
    }

    /**
     * Every meal whose window is active at $at (0, 1 or 2 entries).
     * Deterministic order: breakfast first, lunch second.
     *
     * @return array<int, string>
     */
    public function activeMeals(Carbon $at): array
    {
        $time = $at->format('H:i');
        $windows = $this->settings->mealWindows();

        $active = [];
        foreach (['breakfast', 'lunch'] as $meal) {
            if ($time >= $windows[$meal]['start'] && $time < $windows[$meal]['end']) {
                $active[] = $meal;
            }
        }

        return $active;
    }

    /**
     * Register one meal-reader tap through the full rule set.
     *
     * @return array{ok: true, event: PresenceEvent, meal: string, message: string}
     *                                                                              |array{ok: false, reason: string, message: string, event: PresenceEvent, meal: ?string}
     */
    public function registerMealTap(Reader $reader, Card $card, ?string $clientTimestamp = null): array
    {
        $at = $this->resolveOccurredAt($clientTimestamp);

        // Rule 1 — school days only (Mon–Fri, school-local time).
        if (! $at->isWeekday()) {
            return $this->flag($reader, $card, $at, 'PAE_ATTEMPT', null, self::REASON_WEEKEND);
        }

        // Rule 2 — exactly one active serving window.
        $active = $this->activeMeals($at);

        if (count($active) === 0) {
            return $this->flag($reader, $card, $at, 'PAE_ATTEMPT', null, self::REASON_OUT_OF_WINDOW);
        }

        if (count($active) > 1) {
            // Deterministic, LOUD rejection — the spec forbids silently
            // assigning an ambiguous meal. Settings validation prevents
            // this at write time; this is the engine-side safety net.
            return $this->flag($reader, $card, $at, 'PAE_ATTEMPT', null, self::REASON_WINDOW_OVERLAP);
        }

        $meal = $active[0];
        $type = $meal === 'breakfast' ? 'PAE_BREAKFAST' : 'PAE_LUNCH';

        return DB::transaction(function () use ($reader, $card, $clientTimestamp, $at, $meal, $type) {
            // Serialize concurrent taps of the SAME student (card row
            // lock); unrelated students never block each other.
            Card::whereKey($card->id)->lockForUpdate()->first();

            /** @var Student|null $student */
            $student = $card->student()->first();

            if ($student === null) {
                return $this->flag($reader, $card, $at, $type, $meal, self::REASON_NO_STUDENT);
            }

            // Rule 3 — enrollment for the SPECIFIC meal.
            if (! $student->enrolledForMeal($meal)) {
                return $this->flag($reader, $card, $at, $type, $meal, self::REASON_NOT_ENROLLED);
            }

            // Rule 4 — strictly earlier CLASS_ATTENDANCE, same school-local day.
            if (! $this->hasPriorAttendance($student, $at)) {
                return $this->flag($reader, $card, $at, $type, $meal, self::REASON_NO_ATTENDANCE);
            }

            // Rule 5 — one meal per student per day.
            if ($this->alreadyServed($student, $meal, $at)) {
                return $this->flag($reader, $card, $at, $type, $meal, self::REASON_DUPLICATE);
            }

            $event = PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $reader->id,
                'type' => $type,
                'occurred_at' => $at,
                'metadata' => $clientTimestamp !== null ? ['client_timestamp' => $clientTimestamp] : null,
                'served' => true,
                'reason' => null,
            ]);

            return [
                'ok' => true,
                'event' => $event,
                'meal' => $meal,
                'message' => __('api.pae_meal_served', ['student' => $student->firstName(), 'meal' => __('api.meal_'.$meal)]),
            ];
        });
    }

    /**
     * A flagged/excluded attempt: auditable row, zero statistical weight.
     *
     * @return array{ok: false, reason: string, message: string, event: PresenceEvent, meal: ?string}
     */
    private function flag(Reader $reader, Card $card, Carbon $at, string $type, ?string $meal, string $reason): array
    {
        $event = PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => $reader->id,
            'type' => $type,
            'occurred_at' => $at,
            'metadata' => null,
            'served' => false,
            'reason' => $reason,
        ]);

        Log::info('PAE tap flagged: '.$reason, [
            'student_id' => $card->student_id,
            'credential_uid' => $card->credential_uid,
            'reader_id' => $reader->id,
            'reader_label' => $reader->label,
            'meal' => $meal,
            'reason' => $reason,
        ]);

        return [
            'ok' => false,
            'reason' => $reason,
            'message' => $this->rejectionMessage($reason, $meal, $card),
            'event' => $event,
            'meal' => $meal,
        ];
    }

    /** Device-displayable, meal-specific, localized rejection messages. */
    private function rejectionMessage(string $reason, ?string $meal, Card $card): string
    {
        $student = $card->student;
        $name = $student?->firstName() ?? __('api.pae_unknown_student');

        return match ($reason) {
            self::REASON_WEEKEND => __('api.pae_weekend'),
            self::REASON_OUT_OF_WINDOW => __('api.pae_out_of_window', [
                'breakfast' => $this->windowLabel('breakfast'),
                'lunch' => $this->windowLabel('lunch'),
            ]),
            self::REASON_WINDOW_OVERLAP => __('api.pae_window_overlap'),
            self::REASON_NO_STUDENT => __('api.pae_no_student'),
            // Meal-specific by construction: the message names the meal
            // the student is NOT enrolled for.
            self::REASON_NOT_ENROLLED => __('api.pae_not_enrolled_meal', [
                'student' => $name,
                'meal' => __('api.meal_'.$meal),
            ]),
            self::REASON_NO_ATTENDANCE => __('api.pae_no_attendance', [
                'student' => $name,
                'meal' => __('api.meal_'.$meal),
            ]),
            self::REASON_DUPLICATE => __('api.pae_duplicate', [
                'student' => $name,
                'meal' => __('api.meal_'.$meal),
            ]),
            default => __('api.pae_not_enrolled', ['student' => $name]),
        };
    }

    /** "06:30–08:30" for a meal's configured window (message hint). */
    private function windowLabel(string $meal): string
    {
        $windows = $this->settings->mealWindows();

        return $windows[$meal]['start'].'–'.$windows[$meal]['end'];
    }

    /**
     * Strictly earlier same-day CLASS_ATTENDANCE across all the
     * student's cards. The one presence prerequisite (ENTRY is gone).
     */
    private function hasPriorAttendance(Student $student, Carbon $at): bool
    {
        return PresenceEvent::query()
            ->where('type', 'CLASS_ATTENDANCE')
            ->served()
            ->whereIn('card_id', $student->cards()->pluck('id'))
            ->whereDate('occurred_at', $at->toDateString())
            ->whereTime('occurred_at', '<', $at->format('H:i:s'))
            ->exists();
    }

    /** Already received this meal today (served rows only — flags never count). */
    private function alreadyServed(Student $student, string $meal, Carbon $at): bool
    {
        $type = $meal === 'breakfast' ? 'PAE_BREAKFAST' : 'PAE_LUNCH';

        return PresenceEvent::query()
            ->where('type', $type)
            ->served()
            ->whereIn('card_id', $student->cards()->pluck('id'))
            ->whereDate('occurred_at', $at->toDateString())
            ->exists();
    }

    /**
     * Client timestamps degrade to server time on purpose: a device with
     * a broken clock must never lose the tap (same contract as generic
     * taps — see TapService).
     */
    private function resolveOccurredAt(?string $clientTimestamp): Carbon
    {
        if ($clientTimestamp !== null) {
            try {
                return Carbon::parse($clientTimestamp);
            } catch (\Throwable) {
            }
        }

        return now();
    }
}

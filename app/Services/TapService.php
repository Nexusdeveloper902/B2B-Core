<?php

namespace App\Services;

use App\Enums\ReaderType;
use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Services\Realtime\TapFeedback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The core presence loop (Phase B): tap -> identify -> timestamp -> labeled event.
 * Works identically whether the caller is Postman, a test, or a future ESP32.
 *
 * TASK-037 — meal readers (type `pae`, or any reader relabeled into a PAE
 * mode) delegate to the MealServingService engine: the meal is
 * auto-detected from the admin-configurable serving windows, and every
 * eligibility rule (enrollment, prior attendance, duplicates, weekdays)
 * is enforced BEFORE a row is written. See ADR-053.
 */
class TapService
{
    private MealServingService $meals;

    public function __construct(MealServingService $meals)
    {
        $this->meals = $meals;
    }

    /**
     * TASK-043 — first tap counts: a second classroom tap by the same
     * student for the same type on the same school-local day is a
     * duplicate, not a new event. The original row is returned with
     * duplicate:true (the classify idempotency shape) so a held card,
     * a hand-retry after a timeout, or a replayed request can never
     * inflate attendance. Recycling taps are exempt — every tap there
     * is a separate physical deposit awaiting classification.
     *
     * @return array{ok: true, event: PresenceEvent, duplicate?: bool} on success
     *                                                                 array{ok: false, reason: 'not_found'|'inactive'|'no_student'|'not_enrolled'|'no_attendance'|'out_of_window'|'weekend'|'window_overlap'|'duplicate', message: string, event?: PresenceEvent, meal?: ?string} on rejection
     */
    public function registerTap(Reader $reader, string $credentialUid, ?string $clientTimestamp = null): array
    {
        $card = Card::where('credential_uid', $credentialUid)->first();

        if ($card === null) {
            TapFeedback::record($reader, 'rejected', 'not_found');

            return ['ok' => false, 'reason' => 'not_found', 'message' => __('api.card_not_recognized')];
        }

        if (! $card->isActive()) {
            TapFeedback::record($reader, 'rejected', 'inactive');

            return ['ok' => false, 'reason' => 'inactive', 'message' => __('api.card_not_active')];
        }

        // TASK-037 — cafeteria taps go through the serving engine (auto
        // meal detection from the configured windows; every eligibility
        // rule enforced; rejected attempts flagged, never counted).
        if ($this->meals->isMealReader($reader)) {
            return $this->meals->registerMealTap($reader, $card, $clientTimestamp);
        }

        $occurredAt = $this->resolveOccurredAt($clientTimestamp);
        $type = $this->eventTypeFor($reader);

        return DB::transaction(function () use ($reader, $card, $clientTimestamp, $occurredAt, $type) {
            // Serialize same-student taps (same convention as the meal
            // engine's card lock and the redemption row-lock): two
            // simultaneous first taps converge — the loser answers as
            // the honest duplicate instead of writing a second row.
            if (! $reader->isRecycling() && $card->student_id !== null) {
                Student::whereKey($card->student_id)->lockForUpdate()->first();

                $first = PresenceEvent::query()
                    ->where('served', true)
                    ->where('type', $type)
                    ->whereIn('card_id', Card::where('student_id', $card->student_id)->pluck('id'))
                    ->whereDate('occurred_at', $occurredAt->toDateString())
                    ->orderBy('id')
                    ->first();

                if ($first !== null) {
                    // TASK-047 — no row is written, so the realtime tap
                    // channel stays silent; the feedback channel still
                    // tells a speaker bridge the device answered OK.
                    TapFeedback::record($reader, 'accepted', 'duplicate', $first->id);

                    return ['ok' => true, 'duplicate' => true, 'event' => $first];
                }
            }

            $event = PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $reader->id,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'metadata' => $clientTimestamp !== null ? ['client_timestamp' => $clientTimestamp] : null,
                'served' => true,
                'reason' => null,
            ]);

            return ['ok' => true, 'event' => $event];
        });
    }

    /**
     * The event type a NON-meal tap records. Recycling readers always
     * record deposits; classroom readers record their active mode
     * (CLASS_ATTENDANCE by default, relabelable per the mode endpoint).
     */
    private function eventTypeFor(Reader $reader): string
    {
        if ($reader->type === ReaderType::Recycling) {
            return 'RECYCLING_DEPOSIT';
        }

        return $reader->active_event_type;
    }

    private function resolveOccurredAt(?string $clientTimestamp): Carbon
    {
        if ($clientTimestamp !== null) {
            try {
                return Carbon::parse($clientTimestamp);
            } catch (\Throwable) {
                // Malformed client timestamps degrade to server time on purpose:
                // a device with a broken clock must never lose the tap.
            }
        }

        return now();
    }
}

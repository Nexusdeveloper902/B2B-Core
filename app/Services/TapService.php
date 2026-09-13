<?php

namespace App\Services;

use App\Enums\ReaderType;
use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use Illuminate\Support\Carbon;

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
     * @return array{ok: true, event: PresenceEvent} on success
     *                                               array{ok: false, reason: 'not_found'|'inactive'|'no_student'|'not_enrolled'|'no_attendance'|'out_of_window'|'weekend'|'window_overlap'|'duplicate', message: string, event?: PresenceEvent, meal?: ?string} on rejection
     */
    public function registerTap(Reader $reader, string $credentialUid, ?string $clientTimestamp = null): array
    {
        $card = Card::where('credential_uid', $credentialUid)->first();

        if ($card === null) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => __('api.card_not_recognized')];
        }

        if (! $card->isActive()) {
            return ['ok' => false, 'reason' => 'inactive', 'message' => __('api.card_not_active')];
        }

        // TASK-037 — cafeteria taps go through the serving engine (auto
        // meal detection from the configured windows; every eligibility
        // rule enforced; rejected attempts flagged, never counted).
        if ($this->meals->isMealReader($reader)) {
            return $this->meals->registerMealTap($reader, $card, $clientTimestamp);
        }

        $occurredAt = $this->resolveOccurredAt($clientTimestamp);

        $event = PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => $reader->id,
            'type' => $this->eventTypeFor($reader),
            'occurred_at' => $occurredAt,
            'metadata' => $clientTimestamp !== null ? ['client_timestamp' => $clientTimestamp] : null,
            'served' => true,
            'reason' => null,
        ]);

        return ['ok' => true, 'event' => $event];
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

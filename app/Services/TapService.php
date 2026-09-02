<?php

namespace App\Services;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use Illuminate\Support\Carbon;

/**
 * The core presence loop (Phase B): tap -> identify -> timestamp -> labeled event.
 * Works identically whether the caller is Postman, a test, or a future ESP32.
 */
class TapService
{
    /**
     * @return array{ok: true, event: PresenceEvent} on success
     *                                               array{ok: false, reason: 'not_found'|'inactive', message: string} on rejection
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

        $occurredAt = $this->resolveOccurredAt($clientTimestamp);

        $event = PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => $reader->id,
            'type' => $reader->active_event_type,
            'occurred_at' => $occurredAt,
            'metadata' => $clientTimestamp !== null ? ['client_timestamp' => $clientTimestamp] : null,
        ]);

        return ['ok' => true, 'event' => $event];
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

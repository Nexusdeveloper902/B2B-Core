<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The core presence loop (Phase B): tap -> identify -> timestamp -> labeled event.
 * Works identically whether the caller is Postman, a test, or a future ESP32.
 */
class TapService
{
    /**
     * @return array{ok: true, event: PresenceEvent} on success
     *                                               array{ok: false, reason: 'not_found'|'inactive'|'student_not_pae', message: string} on rejection
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

        // TASK-027 — the PAE enrollment gate: a feeding-program reader in
        // PAE mode must not record a meal for a student who is not
        // enrolled in PAE. The tap is rejected with a clear device-facing
        // message (Accept-Language localized, like every device message)
        // and the attempt is logged for the feeding-program audit trail.
        // Rejection keeps paeCount() honest: attendance comes only from
        // enrolled students' taps.
        $student = $card->student;
        $isPaeMode = in_array(
            $reader->active_event_type,
            [EventType::PaeBreakfast->value, EventType::PaeLunch->value],
            true,
        );

        if ($isPaeMode && ($student === null || ! $student->pae_enrolled)) {
            Log::info('PAE tap rejected: student not enrolled in the feeding program', [
                'student_id' => $student?->id,
                'student_name' => $student?->name,
                'credential_uid' => $credentialUid,
                'reader_id' => $reader->id,
                'reader_label' => $reader->label,
                'event_type' => $reader->active_event_type,
            ]);

            return [
                'ok' => false,
                'reason' => 'student_not_pae',
                'message' => __('api.pae_not_enrolled', ['student' => $student?->firstName() ?? __('api.pae_unknown_student')]),
            ];
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

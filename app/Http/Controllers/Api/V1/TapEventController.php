<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TapEventRequest;
use App\Models\Reader;
use App\Services\TapService;
use Illuminate\Http\JsonResponse;

/**
 * Phase B — the core presence loop.
 *
 * POST /api/v1/events/tap
 * Auth: Authorization: Bearer <reader.api_key> (resolved by reader.auth
 * middleware — client-supplied reader IDs are never trusted).
 *
 * TASK-037 — meal-reader taps are answered by the serving engine (ADR-053):
 * accepted meals return 200 with the auto-detected meal + a localized
 * served message; rejected attempts return 422 with the machine reason,
 * the attempted meal (when a window was active) and a localized,
 * meal-specific message — every rejection is also persisted as a flagged
 * (served=false) events row, so the kitchen feed and the audit trail see
 * the same truth the device just got.
 */
class TapEventController extends Controller
{
    public function __construct(
        private readonly TapService $taps,
    ) {}

    public function store(TapEventRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        $result = $this->taps->registerTap(
            $reader,
            (string) $request->validated('credential_uid'),
            $request->validated('client_timestamp'),
        );

        if (! $result['ok']) {
            // Device-displayable, clear error shape. Unknown/inactive
            // cards stay 404 (nothing to act on); every meal-engine
            // rejection (not enrolled / no attendance / out of window /
            // weekend / duplicate / overlap) and the no-student case are
            // 422 — the card is valid, the request is just not
            // acceptable for the meal program.
            $mealEngineRejection = $result['reason'] !== 'not_found' && $result['reason'] !== 'inactive';
            $status = $mealEngineRejection ? 422 : 404;

            $payload = [
                'status' => 'error',
                'reason' => $result['reason'],
                'message' => $result['message'],
            ];
            if (array_key_exists('meal', $result)) {
                $payload['meal'] = $result['meal'];
            }
            if (isset($result['event'])) {
                // The flagged attempt's id — the audit trail row the
                // kitchen feed and reports will show for this tap.
                $payload['event_id'] = $result['event']->id;
                $payload['event_type'] = $result['event']->type;
            }

            return response()->json($payload, $status);
        }

        $event = $result['event'];
        $student = $event->card->student;

        // Device feedback: what would drive an LED/buzzer/display later.
        // A recycling tap signals the device to proceed to classification.
        $payload = [
            'status' => 'ok',
            'event_id' => $event->id,
            'event_type' => $event->type,
            'student_first_name' => $student->firstName(),
            'next_step' => $reader->isRecycling() ? 'awaiting_classification' : null,
        ];
        if (array_key_exists('meal', $result)) {
            $payload['meal'] = $result['meal'];
            $payload['message'] = $result['message'];
        }

        return response()->json($payload);
    }
}

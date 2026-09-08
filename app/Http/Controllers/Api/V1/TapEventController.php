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
            // cards stay 404 (nothing to act on); the PAE enrollment
            // rejection (TASK-027) is 422 — the card is valid, the
            // request is just not acceptable for this reader's program.
            $status = $result['reason'] === 'student_not_pae' ? 422 : 404;

            return response()->json([
                'status' => 'error',
                'reason' => $result['reason'],
                'message' => $result['message'],
            ], $status);
        }

        $event = $result['event'];
        $student = $event->card->student;

        // Device feedback: what would drive an LED/buzzer/display later.
        // A recycling tap signals the device to proceed to classification.
        return response()->json([
            'status' => 'ok',
            'event_id' => $event->id,
            'event_type' => $event->type,
            'student_first_name' => $student->firstName(),
            'next_step' => $reader->isRecycling() ? 'awaiting_classification' : null,
        ]);
    }
}

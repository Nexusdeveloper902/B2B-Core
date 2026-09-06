<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureAssociateRequest;
use App\Http\Requests\CaptureRequest;
use App\Models\Reader;
use App\Services\Recycling\CaptureService;
use App\Services\Recycling\ClassificationException;
use Illuminate\Http\JsonResponse;

/**
 * TASK-025 item 2 — the bottle-first device endpoints (spec §3 Case B,
 * §5, §32).
 *
 * POST /api/v1/recycling/capture          — image WITHOUT any card:
 *         stored, held awaiting_card (NO classifier call — spec §4
 *         cost gate: never a DeepSeek call before student association).
 * POST /api/v1/recycling/captures/{id}/associate — the card tap that
 *         resolves it: event + classification + points in one call.
 *
 * Auth: the same Bearer reader identity as tap/classify — the capturing
 * station must also be the associating station (403 otherwise).
 */
class RecyclingCaptureController extends Controller
{
    public function __construct(
        private readonly CaptureService $captures,
    ) {}

    public function store(CaptureRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        if (! $reader->isRecycling()) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_not_recycling'),
            ], 422);
        }

        $result = $this->captures->store($reader, $request->file('image'));

        return response()->json([
            'status' => 'ok',
            'capture_id' => $result['capture']->id,
            'state' => $result['capture']->state->value,
            'expires_in' => $result['expires_in'],
            'next_step' => 'present_card',
        ]);
    }

    public function associate(CaptureAssociateRequest $request, int $captureId): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        try {
            $result = $this->captures->associate(
                $reader,
                $captureId,
                (string) $request->validated('credential_uid'),
            );
        } catch (ClassificationException $e) {
            // Driver-level failure (e.g. DeepSeek down): the capture is
            // left in `validating` — the sweep repairs it; devices may
            // retry the same capture with the same card. No points moved.
            return response()->json([
                'status' => 'error',
                'message' => __('api.classifier_unavailable'),
                'detail' => $e->getMessage(),
            ], 503);
        }

        if (! $result['ok']) {
            // Ownership/terminal states are 403/404-shaped; card-level
            // rejections keep the device-displayable 404 shape the tap
            // endpoint uses (the window stays open — retry with the
            // right card).
            $status = match ($result['reason']) {
                'not_owned' => 403,
                'no_pending_capture' => 404,
                default => 404,
            };

            $body = [
                'status' => 'error',
                'reason' => $result['reason'],
                'message' => $result['message'],
            ];
            if (isset($result['state'])) {
                $body['capture_state'] = $result['state'];
            }

            return response()->json($body, $status);
        }

        $award = $result['result'];
        $deposit = $award['deposit'];

        return response()->json([
            'status' => 'ok',
            'capture_id' => $result['capture']->id,
            'capture_state' => $result['capture']->state->value,
            'event_id' => $result['capture']->event_id,
            'already_classified' => $award['duplicate'],
            'material_class' => $deposit->material_class->value,
            'confidence' => (float) $deposit->confidence,
            'is_bottle' => (bool) $deposit->is_bottle,
            'is_recyclable' => (bool) $deposit->is_recyclable,
            'points_awarded' => (int) $deposit->points_awarded,
            'new_balance' => $award['new_balance'],
        ]);
    }
}

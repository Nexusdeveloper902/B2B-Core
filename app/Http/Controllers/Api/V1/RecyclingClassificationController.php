<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyRequest;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Services\Recycling\ClassificationException;
use App\Services\Recycling\ClassificationService;
use Illuminate\Http\JsonResponse;

/**
 * Phase C — recycling classification + points earn.
 *
 * POST /api/v1/recycling/classify
 * Auth: same Bearer reader token as the tap endpoint (must be the
 * recycling reader that owns the event). multipart: event_id + image.
 *
 * Points are awarded here (after classification) — never at tap time.
 */
class RecyclingClassificationController extends Controller
{
    public function __construct(
        private readonly ClassificationService $classification,
    ) {}

    public function store(ClassifyRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        /** @var PresenceEvent $event */
        $event = PresenceEvent::findOrFail($request->validated('event_id'));

        if ((int) $event->reader_id !== (int) $reader->id) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_not_owned_by_reader'),
            ], 403);
        }

        if ($event->type !== EventType::RecyclingDeposit->value) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_not_recycling'),
            ], 422);
        }

        $imagePath = $request->file('image')->getRealPath();

        try {
            $result = $this->classification->classifyAndAward($reader, $event, (string) $imagePath);
        } catch (ClassificationException $e) {
            // Driver-level failure (e.g. local inference service down) —
            // devices may retry; no points were touched.
            return response()->json([
                'status' => 'error',
                'message' => __('api.classifier_unavailable'),
                'detail' => $e->getMessage(),
            ], 503);
        }

        $deposit = $result['deposit'];

        return response()->json([
            'status' => 'ok',
            'already_classified' => $result['duplicate'],
            'material_class' => $deposit->material_class->value,
            'confidence' => (float) $deposit->confidence,
            'points_awarded' => (int) $deposit->points_awarded,
            'new_balance' => $result['new_balance'],
        ]);
    }
}

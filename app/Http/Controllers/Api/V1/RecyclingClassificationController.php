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
use Illuminate\Support\Facades\Storage;

/**
 * Phase C — recycling classification + points earn.
 *
 * POST /api/v1/recycling/classify
 * Auth: same Bearer reader token as the tap endpoint (must be the
 * recycling reader that owns the event). multipart: event_id + image.
 *
 * Points are awarded here (after classification) — never at tap time.
 *
 * TASK-025 items 1/3: the uploaded image is PERSISTED (spec §14 —
 * storage disk 'local', recycling-captures/) and its path rides the
 * deposit; the classify+award pair runs inside one transaction (see
 * ClassificationService). A stale event (tap older than the configured
 * classify window) is rejected — the card-first timeout.
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

        // TASK-025 item 2 — the card-first timeout (spec §5): a tap
        // event must be classified within the configurable window.
        $window = max(0, (int) config('recycling.capture.classify_window_seconds'));
        if ($window > 0 && $event->occurred_at->lt(now()->subSeconds($window))) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.event_expired'),
            ], 422);
        }

        // TASK-025 item 3 — persist the image BEFORE classification: the
        // deposit (and only the deposit) references the stored path; a
        // driver failure cleans the file up so no orphan accumulates.
        $storedPath = $request->file('image')->store('recycling-captures', 'local');
        $absolutePath = (string) Storage::disk('local')->path($storedPath);

        try {
            $result = $this->classification->classifyAndAward($reader, $event, $absolutePath, $storedPath);
        } catch (ClassificationException $e) {
            // Driver-level failure (e.g. local inference service down) —
            // devices may retry; no points were touched.
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'status' => 'error',
                'message' => __('api.classifier_unavailable'),
                'detail' => $e->getMessage(),
            ], 503);
        }

        if ($result['duplicate']) {
            // A retry that lost to the first submission (or a duplicate):
            // the already-stored image from the FIRST call remains the
            // deposit's record; the retry's copy is redundant.
            Storage::disk('local')->delete($storedPath);
        }

        $deposit = $result['deposit'];

        return response()->json([
            'status' => 'ok',
            'already_classified' => $result['duplicate'],
            'material_class' => $deposit->material_class->value,
            'confidence' => (float) $deposit->confidence,
            'is_bottle' => (bool) $deposit->is_bottle,
            'is_recyclable' => (bool) $deposit->is_recyclable,
            'points_awarded' => (int) $deposit->points_awarded,
            'new_balance' => $result['new_balance'],
        ]);
    }
}

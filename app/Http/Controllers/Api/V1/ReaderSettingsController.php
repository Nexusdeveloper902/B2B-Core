<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderSettingsRequest;
use App\Models\Reader;
use Illuminate\Http\JsonResponse;

/**
 * TASK-027 — reader management: label (name) + active mode in ONE
 * settings update (the mode-only endpoint of TASK-006 stays untouched;
 * this one backs the /admin/readers page).
 *
 * PUT /api/v1/admin/readers/{reader} — admin-only.
 */
class ReaderSettingsController extends Controller
{
    public function update(ReaderSettingsRequest $request, Reader $reader): JsonResponse
    {
        $mode = EventType::from((string) $request->validated('active_event_type'));

        $reader->update([
            'label' => (string) $request->validated('label'),
            'active_event_type' => $mode->value,
        ]);

        // Same response shape as ReaderModeController — one reader truth.
        return response()->json([
            'status' => 'ok',
            'reader' => [
                'id' => $reader->id,
                'label' => $reader->label,
                'type' => $reader->type->value,
                'active_event_type' => $reader->active_event_type,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderModeRequest;
use App\Models\Reader;
use Illuminate\Http\JsonResponse;

/**
 * Phase B — reader relabeling. This is how "the same physical reader gets
 * relabeled" (e.g. classroom reader switches to PAE_LUNCH) becomes a real
 * feature instead of a manual DB edit.
 *
 * POST /api/v1/admin/readers/{id}/mode — admin-only.
 */
class ReaderModeController extends Controller
{
    public function update(ReaderModeRequest $request, Reader $reader): JsonResponse
    {
        $mode = EventType::from((string) $request->validated('active_event_type'));

        $reader->update(['active_event_type' => $mode->value]);

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

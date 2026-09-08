<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderSettingsRequest;
use App\Models\Reader;
use App\Models\RosterUpdate;
use App\Services\Realtime\RosterUpdateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        // TASK-029 — the roster frame rides the same transaction (the
        // mode-only endpoint logs one too: whichever surface changed the
        // reader, every surface showing it goes live).
        DB::transaction(function () use ($request, $reader, $mode): void {
            $reader->update([
                'label' => (string) $request->validated('label'),
                'active_event_type' => $mode->value,
            ]);

            RosterUpdateLog::record(RosterUpdate::TYPE_READER_UPDATED, [
                'id' => $reader->id,
                'label' => $reader->label,
                'type' => $reader->type->value,
                'active_event_type' => $reader->active_event_type,
            ]);
        });

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

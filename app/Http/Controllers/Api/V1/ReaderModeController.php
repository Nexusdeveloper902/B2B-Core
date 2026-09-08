<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderModeRequest;
use App\Models\Reader;
use App\Models\RosterUpdate;
use App\Services\Realtime\RosterUpdateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        // TASK-029 — same transaction, one roster frame: every surface
        // showing this reader (readers desk, admin dashboard table) goes
        // live the moment the mode commits.
        DB::transaction(function () use ($reader, $mode): void {
            $reader->update(['active_event_type' => $mode->value]);

            RosterUpdateLog::record(RosterUpdate::TYPE_READER_UPDATED, [
                'id' => $reader->id,
                'label' => $reader->label,
                'type' => $reader->type->value,
                'active_event_type' => $reader->active_event_type,
            ]);
        });

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

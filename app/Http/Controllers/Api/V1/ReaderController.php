<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderStoreRequest;
use App\Models\Reader;
use App\Models\RosterUpdate;
use App\Services\Realtime\RosterUpdateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TASK-030-B (ADR-045) — reader provisioning: create readers from the
 * GUI with a server-generated API key (display-once), and rotate a
 * compromised/lost key without touching SQL.
 *
 * POST /api/v1/admin/readers                 { label, type, active_event_type }
 * POST /api/v1/admin/readers/{reader}/rotate-key   (no body)
 *
 * Both admin-only. The key appears ONLY as the top-level `api_key` of
 * the minting/rotating response — never in the reader object, never in
 * roster frames, never in logs (rotation logs ids only).
 */
class ReaderController extends Controller
{
    public function store(ReaderStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $reader = DB::transaction(function () use ($validated) {
            $reader = Reader::create([
                'label' => $validated['label'],
                'type' => $validated['type'],
                'active_event_type' => $validated['active_event_type'],
                'api_key' => Str::random(32),
            ]);

            // The desk prepends the row live (same transaction =
            // committed-only broadcast). No key material in frames.
            RosterUpdateLog::record(RosterUpdate::TYPE_READER_CREATED, [
                'id' => $reader->id,
                'label' => $reader->label,
                'type' => $reader->type->value,
                'active_event_type' => $reader->active_event_type,
            ]);

            return $reader;
        });

        return response()->json([
            'status' => 'ok',
            'reader' => [
                'id' => $reader->id,
                'label' => $reader->label,
                'type' => $reader->type->value,
                'active_event_type' => $reader->active_event_type,
            ],
            // Display-once (the student-login rule, ADR-044): this
            // response is the ONLY place the key ever appears.
            'api_key' => $reader->api_key,
            'message' => __('api.reader_created', ['label' => $reader->label]),
            'api_key_notice' => __('api.reader_key_notice'),
        ]);
    }

    public function rotateKey(Reader $reader): JsonResponse
    {
        $fresh = Str::random(32);

        DB::transaction(function () use ($reader, $fresh): void {
            $reader->update(['api_key' => $fresh]);
        });

        // Audit WITHOUT key material: who rotated which reader, never
        // the secret itself.
        Log::info('reader api key rotated', [
            'reader_id' => $reader->id,
            'reader_label' => $reader->label,
            'rotated_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => 'ok',
            'reader' => [
                'id' => $reader->id,
                'label' => $reader->label,
            ],
            'api_key' => $fresh,
            'message' => __('api.reader_key_rotated', ['label' => $reader->label]),
            'api_key_notice' => __('api.reader_key_notice'),
        ]);
    }
}

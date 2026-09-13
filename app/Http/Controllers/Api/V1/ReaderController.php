<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReaderStoreRequest;
use App\Models\PendingCapture;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RosterUpdate;
use App\Services\Realtime\RosterUpdateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * DELETE /api/v1/admin/readers/{reader} — admin-only.
     *
     * The reader row goes; its tap events + recycling deposits +
     * pending captures go with it (explicit child-first deletes —
     * same order a DB cascade would apply, deterministic where the
     * sqlite foreign_key pragma is off, cf. CardUnpairController).
     * Pairing history rows survive with the reader link cleared
     * (nullOnDelete audit trail); points ledger rows survive with
     * event_id cleared (balances keep their value). Stored capture
     * images follow their rows so nothing orphans on disk.
     */
    public function destroy(Reader $reader): JsonResponse
    {
        $id = $reader->id;
        $label = $reader->label;

        [$eventsDeleted, $imagePaths] = DB::transaction(function () use ($reader, $id, $label): array {
            $eventIds = PresenceEvent::where('reader_id', $reader->id)->pluck('id');
            $imagePaths = RecyclingDeposit::whereIn('event_id', $eventIds)
                ->whereNotNull('image_path')->pluck('image_path')->all();
            $imagePaths = array_merge($imagePaths, PendingCapture::where('reader_id', $reader->id)
                ->whereNotNull('image_path')->pluck('image_path')->all());

            RecyclingDeposit::whereIn('event_id', $eventIds)->delete();
            PresenceEvent::where('reader_id', $reader->id)->delete();
            PendingCapture::where('reader_id', $reader->id)->delete();
            PendingPairing::where('reader_id', $reader->id)->update(['reader_id' => null]);

            $eventsDeleted = $eventIds->count();
            $reader->delete();

            RosterUpdateLog::record(RosterUpdate::TYPE_READER_DELETED, [
                'id' => $id,
                'label' => $label,
            ]);

            return [$eventsDeleted, $imagePaths];
        });

        foreach ($imagePaths as $path) {
            Storage::disk('local')->delete($path);
        }

        Log::info('reader deleted', [
            'reader_id' => $id,
            'reader_label' => $label,
            'deleted_by' => auth()->id(),
            'events_deleted' => $eventsDeleted,
        ]);

        return response()->json([
            'status' => 'ok',
            'deleted' => [
                'id' => $id,
                'label' => $label,
                'events_deleted' => $eventsDeleted,
            ],
            'message' => __('api.reader_deleted', ['label' => $label]),
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TASK-027 — per-card unpair (the GUI half of gap D1).
 *
 * DELETE /api/v1/admin/cards/{card} — admin-only.
 *
 * Semantics mirror the bulk `cards:unpair` command (ADR-023) at
 * single-card granularity: "fresh" means the row does not exist, so
 * unpairing DELETES the cards row (never nulls student_id — a nulled
 * row would still block re-pairing). Tap events cascade with the card;
 * pending_pairings history links are cleared but the history rows
 * survive (nullOnDelete audit trail). One transaction, same order a
 * DB-level cascade would apply, so the outcome is deterministic even
 * where the sqlite foreign_key pragma is off.
 */
class CardUnpairController extends Controller
{
    public function destroy(Card $card): JsonResponse
    {
        $credentialUid = $card->credential_uid;
        $studentName = $card->student?->name;

        [$eventsDeleted, $linksCleared] = DB::transaction(function () use ($card): array {
            $eventsDeleted = PresenceEvent::where('card_id', $card->id)->count();
            PresenceEvent::where('card_id', $card->id)->delete();

            $linksCleared = PendingPairing::where('card_id', $card->id)
                ->update(['card_id' => null]);

            $card->delete();

            return [$eventsDeleted, $linksCleared];
        });

        return response()->json([
            'status' => 'ok',
            'unpaired' => [
                'credential_uid' => $credentialUid,
                'student_name' => $studentName,
                'events_deleted' => $eventsDeleted,
                'history_links_cleared' => $linksCleared,
            ],
            'message' => __('api.card_unpaired', ['student' => $studentName ?? '—']),
        ]);
    }
}

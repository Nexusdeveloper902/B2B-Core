<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CardStatus;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\HceCredentialKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TASK-049 (ADR-068) — revoke a credential (lost/stolen phone or card).
 *
 * POST /api/v1/admin/cards/{card}/revoke — admin-only, school-scoped by
 * route-model binding (a foreign school's card answers 404).
 *
 * Unlike unpair, history is KEPT: the row stays with status `revoked`
 * (taps answer 404 `inactive`, re-pairing the id answers 422), and a
 * phone credential's key is destroyed in the same transaction, so even
 * a hand-edited status could not make that key authenticate again.
 * Unpairing a revoked card later makes the id fresh, as always.
 */
class CardRevokeController extends Controller
{
    public function store(Card $card): JsonResponse
    {
        $keyDestroyed = DB::transaction(function () use ($card): bool {
            $card->update(['status' => CardStatus::Revoked]);

            return HceCredentialKey::where('card_id', $card->id)->delete() > 0;
        });

        return response()->json([
            'status' => 'ok',
            'revoked' => [
                'card_id' => $card->id,
                'credential_uid' => $card->credential_uid,
                'kind' => $card->kind?->value ?? 'physical',
                'key_destroyed' => $keyDestroyed,
            ],
            'message' => __('api.card_revoked', ['student' => $card->student?->name ?? '—']),
        ]);
    }
}

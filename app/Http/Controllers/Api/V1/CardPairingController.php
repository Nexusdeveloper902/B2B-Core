<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PairCardRequest;
use App\Models\Reader;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

/**
 * TASK-010 — pair a scanned card (device side of the two-step
 * arm-then-pair flow).
 *
 * POST /api/v1/admin/cards/pair — Auth: Bearer <reader.api_key>
 * (resolved by reader.auth middleware; the reader is who the key says it
 * is, never a client-supplied value — identical identity pattern to the
 * tap endpoint).
 */
class CardPairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function store(PairCardRequest $request): JsonResponse
    {
        /** @var Reader $reader */
        $reader = $request->attributes->get('reader');

        $result = $this->pairings->pair(
            $reader,
            (string) $request->validated('credential_uid'),
        );

        if (! $result['ok']) {
            if ($result['reason'] === 'no_session') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('api.pairing_no_active_session'),
                ], 409);
            }

            // already_paired — never silently reassign a card.
            return response()->json([
                'status' => 'error',
                'message' => __('api.pairing_card_already_paired'),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'paired_student_name' => $result['student']->name,
            'student_id' => $result['student']->id,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

/**
 * TASK-011 — read-only pairing status for the dashboard pairing desk
 * (GET /api/v1/admin/pairing/status, admin session/PAT). The page polls
 * this while a session is armed: it reports the active window, the last
 * completed pairing, and the recent history — so the operator sees the
 * card->student link the moment the reader consumes the session, without
 * watching the serial monitor. No write path exists here by design.
 */
class PairingStatusController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function show(): JsonResponse
    {
        // TASK-020 — the payload is built by PairingService::statusPayload(),
        // the ONE truth shared with the realtime pairing frames: the desk's
        // poll path and its WebSocket path serialize the identical state.
        return response()->json(
            ['status' => 'ok'] + $this->pairings->statusPayload(),
        );
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Http\JsonResponse;

/**
 * TASK-016 — mints the short-lived feed token the dashboard's
 * WebSocket handshake needs. Session-authed (web route, admin/teacher
 * roles) — the socket process itself never touches sessions; it only
 * verifies this HMAC token.
 */
class RealtimeTokenController extends Controller
{
    public function issue(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return response()->json([
            'token' => RealtimeToken::issue((int) $user->id),
            'expires_at' => RealtimeToken::freshExpiry(),
            'url' => 'ws://'.request()->getHost().':'.(int) config('realtime.port').'/app',
        ]);
    }
}

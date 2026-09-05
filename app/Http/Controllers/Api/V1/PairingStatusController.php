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
        $active = $this->pairings->activeSession();
        $recent = $this->pairings->recentCompletions(8);

        return response()->json([
            'status' => 'ok',
            'pending' => $active !== null ? [
                'student_id' => $active->student_id,
                'student_name' => $active->student?->name,
                'expires_at' => $active->expires_at->toIso8601String(),
                'seconds_left' => max(0, (int) now()->diffInSeconds($active->expires_at)),
            ] : null,
            'last_pairing' => $recent->isNotEmpty() ? [
                'card_uid' => $recent->first()->card?->credential_uid,
                'student_name' => $recent->first()->student?->name,
                'paired_at' => $recent->first()->consumed_at?->toIso8601String(),
                'reader_label' => $recent->first()->reader?->label,
            ] : null,
            'recent_pairings' => $recent->map(fn ($p) => [
                'card_uid' => $p->card?->credential_uid,
                'student_name' => $p->student?->name,
                'paired_at' => $p->consumed_at?->toIso8601String(),
                'reader_label' => $p->reader?->label,
            ])->values()->all(),
        ]);
    }
}

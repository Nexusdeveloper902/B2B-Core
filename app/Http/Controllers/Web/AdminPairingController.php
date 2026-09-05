<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PairingService;
use Illuminate\View\View;

/**
 * TASK-011 — the pairing desk: the admin dashboard page that makes arming
 * a card pairing a one-click action (the deferred follow-up of ADR-020).
 *
 * The page READS pairing state and renders arm buttons; the actual arming
 * POST goes to the existing TASK-010 API endpoint
 * (POST /api/v1/admin/students/{id}/arm-pairing) from the page's script,
 * using the admin's own session — no PAT, no curl. The write path and the
 * arm-then-pair security model are unchanged.
 */
class AdminPairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function page(): View
    {
        $active = $this->pairings->activeSession();
        $last = $this->pairings->recentCompletions(1)->first();

        return view('admin.pairing', [
            'students' => Student::orderBy('name')
                ->with(['schoolClass', 'cards'])
                ->get(),
            'activeSession' => $active,
            'activeSecondsLeft' => $active !== null
                ? max(0, (int) now()->diffInSeconds($active->expires_at))
                : null,
            'recentPairings' => $this->pairings->recentCompletions(8),
            'lastCardUid' => $last?->card?->credential_uid,
        ]);
    }
}

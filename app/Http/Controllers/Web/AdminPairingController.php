<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PairingService;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Http\Request;
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
 *
 * TASK-020 — the desk is a realtime page: the page render mints the same
 * short-lived feed token the dashboards mint (SSR-first), so realtime.js
 * can open the pairing channel and the desk updates the instant a card
 * is paired. The status poll in the page script stays as the honest
 * fallback when the socket is down (ADR-029).
 */
class AdminPairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function page(Request $request): View
    {
        $active = $this->pairings->activeSession();
        $last = $this->pairings->recentCompletions(1)->first();

        // TASK-014 — pre-render the armed window's rejection note (if any)
        // so an F5 mid-window shows it immediately; the desk script keeps
        // it fresh from the status feed afterwards.
        $rejectionNote = null;
        if ($active !== null && $active->last_rejected_uid !== null) {
            $rejectionNote = __('app.pairing_rejected', [
                'uid' => $active->last_rejected_uid,
                'reason' => __('app.pairing_reason_'.$active->last_rejected_reason),
            ]);
        }

        // Grades present, numerically ordered ('10°' after '9°', not
        // after '1°' — the column is free text, so PHP owns the sort).
        $grades = Student::selectRaw('grade, COUNT(*) AS c')
            ->groupBy('grade')
            ->get()
            ->sortBy(fn ($row) => (int) $row->grade)
            ->mapWithKeys(fn ($row) => [$row->grade => (int) $row->c])
            ->all();

        $requested = (string) $request->query('grade', '');
        $grade = array_key_exists($requested, $grades) ? $requested : array_key_first($grades);

        $students = $grade === null
            ? collect()
            : Student::where('grade', $grade)->orderBy('name')
                ->with(['schoolClass', 'cards'])
                ->get();

        return view('admin.pairing', [
            // The roster is one grade at a time (300 names on one page
            // is unusable): the grade menu (?grade=5°) switches, the
            // default is the lowest grade with students, and an unknown
            // grade falls back to that default instead of 404ing.
            'students' => $students,
            'grades' => $grades,
            'activeGrade' => $grade,
            'activeSession' => $active,
            'activeSecondsLeft' => $active !== null
                ? max(0, (int) now()->diffInSeconds($active->expires_at))
                : null,
            'pairingWindowSeconds' => settings()->pairingWindowSeconds(),
            'recentPairings' => $this->pairings->recentCompletions(8),
            'lastCardUid' => $last?->card?->credential_uid,
            'activeRejectionNote' => $rejectionNote,
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}

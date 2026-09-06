<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-025 item 4 — the leaderboard (spec §22/§28).
 *
 * GET /api/v1/recycling/leaderboard — global ranking derived EXCLUSIVELY
 * from the real points ledger, deterministic tie-breaking (points DESC,
 * student id ASC, competition ranks). A student user also receives
 * their OWN standing (`me`) — resolved from their account's student row,
 * never from a client-supplied id.
 *
 * Auth: admin, teacher, or student (session/PAT via auth:sanctum).
 */
class LeaderboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', config('recycling.leaderboard.top'))));

        $payload = [
            'status' => 'ok',
            'entries' => $this->leaderboard->top($limit),
        ];

        $user = $request->user();
        if ($user !== null && $user->isStudent() && $user->student !== null) {
            $payload['me'] = $this->leaderboard->rankOf($user->student) + [
                'student_id' => $user->student->id,
                'student_name' => $user->student->name,
            ];
        }

        return response()->json($payload);
    }
}

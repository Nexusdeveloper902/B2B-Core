<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-025 item 5 — student self-service (spec §11/§12/§30).
 *
 * Server-side authorization is the whole design: the student row is
 * resolved from the AUTHENTICATED user's account (users.student_id),
 * never from a URL parameter — every query is scoped to that one
 * student. Students see exactly their own points, history, rewards.
 */
class StudentDashboardController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
    ) {}

    public function dashboard(Request $request): View
    {
        $student = $request->user()->student;

        $standing = $this->leaderboard->rankOf($student);
        $recent = $student->pointsLedger()
            ->latest('id')
            ->limit(5)
            ->get();

        return view('student.dashboard', [
            'student' => $student,
            'balance' => $student->pointBalance(),
            'rank' => $standing['rank'],
            'recentLedger' => $recent,
            'leaderboard' => $this->leaderboard->top(5),
        ]);
    }

    public function history(Request $request): View
    {
        $student = $request->user()->student;

        return view('student.history', [
            'student' => $student,
            'ledger' => $student->pointsLedger()
                ->latest('id')
                ->paginate(25),
            'balance' => $student->pointBalance(),
        ]);
    }

    public function rewards(Request $request): View
    {
        $student = $request->user()->student;

        return view('student.rewards', [
            'student' => $student,
            'balance' => $student->pointBalance(),
            'rewards' => Reward::query()->orderBy('point_cost')->get(),
            'redemptions' => $student->redemptions()
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * TASK-026 — the student-facing standings page (mockup "Leaderboard
     * & Class Standings"). Read-only view over the SAME LeaderboardService
     * the API and the student desk use; class standings are derived from
     * the board's own class_name column (no new aggregates, no new
     * endpoints). Every student still sees the full school board — the
     * same data GET /api/v1/recycling/leaderboard already publishes.
     */
    public function leaderboard(Request $request): View
    {
        $student = $request->user()->student;

        $board = $this->leaderboard->top(50);

        $classStandings = collect($board)
            ->filter(fn (array $entry) => ! empty($entry['class_name']))
            ->groupBy('class_name')
            ->map(fn ($entries, $className) => [
                'class_name' => $className,
                'students' => count($entries),
                'points' => (int) $entries->sum('points'),
            ])
            ->sortByDesc('points')
            ->values()
            ->take(8)
            ->all();

        return view('student.leaderboard', [
            'student' => $student,
            'balance' => $student->pointBalance(),
            'board' => $board,
            'top3' => array_slice($board, 0, 3),
            'myRank' => $this->leaderboard->rankOf($student),
            'classStandings' => $classStandings,
        ]);
    }
}

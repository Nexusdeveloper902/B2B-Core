<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Reader;
use App\Models\Reward;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimeToken;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly RealtimeFeed $realtimeFeed,
    ) {}

    public function dashboard(): View
    {
        $today = now()->toDateString();

        $recyclingToday = $this->attendance->recyclingTotals($today, $today);

        return view('admin.dashboard', [
            'attendanceToday' => $this->attendance->attendanceCount($today),
            'paeBreakfastToday' => $this->attendance->paeCount('breakfast', $today),
            'paeLunchToday' => $this->attendance->paeCount('lunch', $today),
            'recyclingToday' => $recyclingToday,
            'readers' => Reader::orderBy('label')->get(),
            'students' => Student::orderBy('name')->with('schoolClass')->get(),
            'rewards' => Reward::orderBy('point_cost')->get(),
            'nlQueryConfigured' => ! empty(config('recycling.nl_query.api_key')),
            'recentEvents' => $this->realtimeFeed->recent((int) config('realtime.history_limit')),
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}

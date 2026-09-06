<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimeToken;
use Illuminate\View\View;

class TeacherDashboardController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly RealtimeFeed $realtimeFeed,
    ) {}

    public function dashboard(): View
    {
        /** @var User $user */
        $user = auth()->user();

        // Teachers see their own assigned classes; an admin landing on
        // /teacher sees every class (school-wide overview).
        $classes = $user->isTeacher()
            ? $user->classes()->with('teacher')->orderBy('name')->get()
            : SchoolClass::with('teacher')->orderBy('name')->get();

        $attendanceByClass = $classes->mapWithKeys(function ($class) {
            return [$class->id => $this->attendance->classAttendanceToday($class->id)];
        });

        $cutoff = (string) config('presence.late_cutoff');

        return view('teacher.dashboard', [
            'classes' => $classes,
            'attendanceByClass' => $attendanceByClass,
            'cutoff' => $cutoff,
            'recentEvents' => $this->realtimeFeed->recent((int) config('realtime.history_limit')),
            'realtimeToken' => RealtimeToken::issue((int) $user->id),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}

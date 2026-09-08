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

        // TASK-027 — the SSR live feed honors the teacher data wall: rows
        // are scoped to the classes this teacher teaches (the WS server
        // applies the same scope per connection). Admins keep the
        // school-wide plane.
        $feedClassIds = $user->isTeacher()
            ? $user->classes()->pluck('classes.id')->values()->all()
            : null;

        return view('teacher.dashboard', [
            'classes' => $classes,
            'attendanceByClass' => $attendanceByClass,
            'cutoff' => $cutoff,
            'recentEvents' => $this->realtimeFeed->recent((int) config('realtime.history_limit'), $feedClassIds),
            'realtimeToken' => RealtimeToken::issue((int) $user->id),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
            // TASK-027 — the teacher's own NL query desk renders only when
            // the LLM credential exists (same honesty rule as the admin box).
            'nlQueryConfigured' => ! empty(config('recycling.nl_query.api_key')),
        ]);
    }
}

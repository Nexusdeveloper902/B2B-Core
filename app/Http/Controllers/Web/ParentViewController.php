<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\Realtime\RealtimeToken;
use App\Services\StudentScope;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParentViewController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Simplified parent view: a single student's event timeline.
     * Selected by an admin/teacher ("view as parent for student X") — a
     * real parent-auth system is intentionally out of scope here.
     *
     * TASK-027 — the data wall: a teacher may only open timelines of
     * students in the classes they teach (admins stay school-wide).
     */
    public function timeline(Request $request, Student $student): View
    {
        $scope = StudentScope::forUser($request->user());
        abort_unless($scope->allowsStudent($student), 403, __('api.forbidden_role'));

        $timeline = $this->attendance->studentTimeline($student);

        $points = (int) $student->pointsLedger()->sum('delta');

        return view('parent.timeline', [
            'student' => $student,
            'timeline' => $timeline,
            'points' => $points,
            'paeEnrolled' => $student->pae_enrolled,
            // TASK-029 — the timeline is LIVE: tap frames (already scoped
            // per role on the wire) prepend this student's new events as
            // they happen — the "live anchor" below becomes a real badge.
            'realtimeToken' => RealtimeToken::issue((int) $request->user()->id),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}

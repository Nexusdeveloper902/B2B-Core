<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AttendanceService;
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
     */
    public function timeline(Student $student): View
    {
        $timeline = $this->attendance->studentTimeline($student);

        $points = (int) $student->pointsLedger()->sum('delta');

        return view('parent.timeline', [
            'student' => $student,
            'timeline' => $timeline,
            'points' => $points,
            'paeEnrolled' => $student->pae_enrolled,
        ]);
    }
}

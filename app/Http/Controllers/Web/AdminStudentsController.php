<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * TASK-027 — the student management desk (/admin/students): searchable
 * roster, single-student creation, and CSV bulk import. The write paths
 * are the admin API endpoints (statefulApi fetch pattern — same as the
 * pairing desk); this controller only renders server-side state.
 */
class AdminStudentsController extends Controller
{
    public function page(Request $request): View
    {
        // LIKE wildcards are stripped, not escaped (same rule as the NL
        // name lookup — the roster search is a plain contains-match).
        $search = str_replace(['%', '_'], '', trim((string) $request->query('q', '')));

        $classes = SchoolClass::orderBy('name')->get();

        $students = Student::query()
            ->with(['schoolClass', 'cards'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students', [
            'classes' => $classes,
            'students' => $students,
            'search' => $search,
        ]);
    }
}

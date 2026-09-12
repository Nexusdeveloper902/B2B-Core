<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Realtime\RealtimeToken;
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
            // TASK-030-A — the account column renders the provisioned
            // login (or the one-click backfill for pre-feature rows).
            ->with(['schoolClass', 'cards', 'account'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.students', [
            'classes' => $classes,
            'students' => $students,
            'search' => $search,
            // TASK-029 — the class-create form's optional homeroom
            // teacher select (staff only; admins may create classes
            // before assigning anyone).
            'teachers' => User::where('role', UserRole::Teacher->value)
                ->orderBy('name')
                ->get(['id', 'name']),
            // TASK-029 — the desk is LIVE: roster frames prepend created
            // students / add created classes the moment they commit.
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}

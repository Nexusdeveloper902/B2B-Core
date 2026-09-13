<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * TASK-038 — the staff accounts desk (/admin/staff): list the
 * admin/teacher/kitchen logins with their homeroom classes, and create
 * new ones (admin-chosen temporary password + forced first-login
 * rotation; teachers optionally take homeroom classes in the same
 * request). The write path is the admin API endpoint (statefulApi
 * fetch pattern — same as the students desk); this controller only
 * renders server-side state.
 *
 * Student logins are deliberately NOT here: they are the 1:1 account
 * layer minted by the students desk (ADR-033/ADR-044).
 */
class AdminStaffController extends Controller
{
    public function page(): View
    {
        $staff = User::query()
            ->whereIn('role', [UserRole::Admin->value, UserRole::Teacher->value, UserRole::Kitchen->value])
            ->with('classes')
            ->orderBy('name')
            ->get();

        // The picker offers ONLY classes without a homeroom teacher —
        // one class, one teacher; already-homed classes never appear.
        $classes = SchoolClass::orderBy('name')->whereNull('teacher_user_id')->get();

        return view('admin.staff', [
            'staff' => $staff,
            'classes' => $classes,
            'roles' => [UserRole::Admin->value, UserRole::Teacher->value, UserRole::Kitchen->value],
            // The email-domain preset from the settings desk (the same
            // accounts preset family as the initial password): the form
            // auto-suggests {name}.{role}@{domain}, editable.
            'emailDomain' => (string) settings()->studentEmailDomain(),
        ]);
    }
}

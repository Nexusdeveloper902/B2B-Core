<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-030-A (ADR-044) — first-login password rotation.
 *
 * GET  /password/change  (password.change) — the rotation form. Open to
 * any authenticated user (forced for flagged accounts, voluntary for
 * everyone else — the rule is identical, only the entry differs).
 *
 * PUT  /password/change  (password.update) — validates the current
 * password plus a confirmed minimum-8 replacement, stores it, clears
 * users.must_change_password, and lands on the role's own dashboard.
 */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        // Adversarial finding 4.1: without `different` + the initial
        // blacklist, submitting the CURRENT (shared, documented) value
        // as the "new" password cleared the flag and left the account
        // on the well-known secret — the rotation control was theater.
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                'different:current_password',
                Rule::notIn([(string) config('presence.student_initial_password', 'password')]),
            ],
        ]);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->save();

        // Privilege change (finding 4.3): a stolen pre-rotation session
        // must not survive the rotation — same regenerate() login uses.
        $request->session()->regenerate();

        $fallback = $user->isStudent() ? route('student.dashboard') : route('dashboard');

        return redirect()->to($fallback)
            ->with('status', __('auth.password_changed'));
    }
}

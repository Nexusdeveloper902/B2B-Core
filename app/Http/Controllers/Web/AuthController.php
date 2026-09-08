<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        if (! auth()->attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        // TASK-025 item 5 — students land on their self-service desk,
        // staff on the staff dashboard (students have no /dashboard).
        //
        // TASK-027 — a stale `url.intended` used to override the role's
        // own landing page: a guest hitting /teacher stored it, the
        // student then logged in, intended() replayed /teacher and the
        // role wall answered 403. The intended URL survives only when
        // the freshly-authenticated role can actually open it.
        $user = auth()->user();
        $intended = $request->session()->pull('url.intended');

        return redirect()->to(
            $this->postLoginTarget($user, $intended)
        );
    }

    private function postLoginTarget(User $user, ?string $intended): string
    {
        $fallback = $user->isStudent() ? route('student.dashboard') : route('dashboard');

        if ($intended === null) {
            return $fallback;
        }

        $path = (string) (parse_url($intended, PHP_URL_PATH) ?: '/');

        if ($user->isStudent() && $this->isStaffOnlyPath($path)) {
            return $fallback;
        }

        if (! $user->isStudent() && str_starts_with($path, '/student')) {
            return $fallback;
        }

        if ($user->isTeacher() && str_starts_with($path, '/admin')) {
            return $fallback;
        }

        return $intended;
    }

    private function isStaffOnlyPath(string $path): bool
    {
        return str_starts_with($path, '/admin')
            || str_starts_with($path, '/teacher')
            || str_starts_with($path, '/dashboard')
            || str_starts_with($path, '/parent');
    }

    public function logout(): RedirectResponse
    {
        auth()->logout();

        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login');
    }
}

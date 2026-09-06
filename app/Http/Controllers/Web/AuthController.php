<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
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
        return redirect()->intended(
            auth()->user()->isStudent() ? route('student.dashboard') : route('dashboard')
        );
    }

    public function logout(): RedirectResponse
    {
        auth()->logout();

        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login');
    }
}

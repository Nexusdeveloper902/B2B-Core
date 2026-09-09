<?php

use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AdminPairingController;
use App\Http\Controllers\Web\AdminReadersController;
use App\Http\Controllers\Web\AdminStudentsController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ParentViewController;
use App\Http\Controllers\Web\RealtimeTokenController;
use App\Http\Controllers\Web\StudentDashboardController;
use App\Http\Controllers\Web\TeacherDashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Landing -> login (or the role-appropriate dashboard when authenticated).
Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    // TASK-025 item 5 — students have no staff dashboard; send them home.
    return redirect()->route(
        auth()->user()->isStudent() ? 'student.dashboard' : 'dashboard'
    );
})->name('home');

// Language switcher (EN / ES).
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, (array) config('presence.locales', ['en']), true)) {
        session(['locale' => $locale]);
    }

    return redirect()->back();
})->name('locale.switch');

// Guest-only. Login is throttled (AppServiceProvider 'login' limiter) —
// brute-force guard, no UX change for a human typing credentials.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Authenticated dashboards.
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [TeacherDashboardController::class, 'dashboard'])
        ->middleware('role:admin,teacher')
        ->name('dashboard');

    Route::get('/teacher', [TeacherDashboardController::class, 'dashboard'])
        ->middleware('role:admin,teacher')
        ->name('teacher.dashboard');

    Route::get('/admin', [AdminDashboardController::class, 'dashboard'])
        ->middleware('role:admin')
        ->name('admin.dashboard');

    // TASK-011 — the pairing desk: one-click arming page (admin-only).
    // The arming POST itself goes to the existing TASK-010 API endpoint.
    Route::get('/admin/pairing', [AdminPairingController::class, 'page'])
        ->middleware('role:admin')
        ->name('admin.pairing');

    // TASK-026 — the EcoStation hub (mockup-driven, read-only view over
    // existing deposit/reader/config data; admin-only).
    Route::get('/admin/ecostation', [AdminDashboardController::class, 'ecostation'])
        ->middleware('role:admin')
        ->name('admin.ecostation');

    // TASK-027 — the student management desk: roster + single creation
    // + CSV bulk import (admin-only; writes go through the admin API).
    Route::get('/admin/students', [AdminStudentsController::class, 'page'])
        ->middleware('role:admin')
        ->name('admin.students');

    // TASK-027 — the reader management desk: rename + switch mode
    // (admin-only; the write goes through the admin settings API).
    Route::get('/admin/readers', [AdminReadersController::class, 'page'])
        ->middleware('role:admin')
        ->name('admin.readers');

    // TASK-025 item 5 — student self-service (spec §11/§12/§30): own
    // points, history, rewards. The student row always resolves from
    // the authenticated account — never a URL parameter.
    Route::get('/student', [StudentDashboardController::class, 'dashboard'])
        ->middleware('role:student')
        ->name('student.dashboard');

    Route::get('/student/history', [StudentDashboardController::class, 'history'])
        ->middleware('role:student')
        ->name('student.history');

    Route::get('/student/rewards', [StudentDashboardController::class, 'rewards'])
        ->middleware('role:student')
        ->name('student.rewards');

    // TASK-026 — the standings page (mockup "Leaderboard & Class
    // Standings"): read-only view over LeaderboardService data.
    Route::get('/student/leaderboard', [StudentDashboardController::class, 'leaderboard'])
        ->middleware('role:student')
        ->name('student.leaderboard');

    // Simplified parent view (admin/teacher selectable stand-in — a real
    // parent auth system is explicitly out of scope for this phase).
    Route::get('/parent/students/{student}', [ParentViewController::class, 'timeline'])
        ->middleware('role:admin,teacher')
        ->name('parent.timeline');

    // TASK-016 — feed token for the dashboard's WebSocket handshake
    // (session-authed; the socket process verifies the HMAC, not the
    // session). TASK-025: students too — recycling frames carry the
    // same student names the tap frames already do (no card UIDs).
    Route::get('/realtime/token', [RealtimeTokenController::class, 'issue'])
        ->middleware('role:admin,teacher,student')
        ->name('realtime.token');
});

// UI pass 2026-09-09 — unknown web paths render the in-shell 404. As a
// MATCHED route this still runs the web middleware (session, locale,
// auth), so the page is localized and shows the user's own way out; a
// bare abort(404) renders before group middleware and always comes out
// guest-mode English. A fallback also intercepts requests the router
// would have answered with 405, so the verb verdict is rebuilt here:
// a known path probed with the wrong method keeps its 405 (with Allow),
// and API/JSON misses re-throw NotFound so their response shape is
// byte-identical to before.
Route::fallback(function (Request $request) {
    $allowed = [];
    $path = $request->path();
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->isFallback) {
            continue;
        }
        $pattern = '#^'.preg_replace(
            ['#/\{[^}]+\?\}#', '#\{[^}]+\}#'],
            ['(?:/[^/]+)?', '[^/]+'],
            $route->uri(),
        ).'$#';

        if (preg_match($pattern, $path) === 1) {
            $allowed = array_merge($allowed, $route->methods());
        }
    }
    $allowed = array_values(array_unique(array_diff($allowed, ['HEAD'])));

    if ($allowed !== []) {
        throw new MethodNotAllowedHttpException($allowed);
    }

    if ($request->is('api/*') || $request->expectsJson()) {
        // Same wording the router itself throws (visible under APP_DEBUG).
        throw new NotFoundHttpException("The route {$path} could not be found.");
    }

    return response()->view('errors.404', ['code' => 404], 404);
});

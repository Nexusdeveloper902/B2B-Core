<?php

use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AdminPairingController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ParentViewController;
use App\Http\Controllers\Web\RealtimeTokenController;
use App\Http\Controllers\Web\StudentDashboardController;
use App\Http\Controllers\Web\TeacherDashboardController;
use Illuminate\Support\Facades\Route;

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

// Guest-only.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
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

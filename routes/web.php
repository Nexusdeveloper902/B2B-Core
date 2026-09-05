<?php

use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AdminPairingController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ParentViewController;
use App\Http\Controllers\Web\TeacherDashboardController;
use Illuminate\Support\Facades\Route;

// Landing -> login (or dashboard when already authenticated).
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
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

    // Simplified parent view (admin/teacher selectable stand-in — a real
    // parent auth system is explicitly out of scope for this phase).
    Route::get('/parent/students/{student}', [ParentViewController::class, 'timeline'])
        ->middleware('role:admin,teacher')
        ->name('parent.timeline');
});

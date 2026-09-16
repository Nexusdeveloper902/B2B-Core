<?php

use App\Http\Controllers\Web\AdminDashboardController;
use App\Http\Controllers\Web\AdminPairingController;
use App\Http\Controllers\Web\AdminReadersController;
use App\Http\Controllers\Web\AdminSettingsController;
use App\Http\Controllers\Web\AdminStaffController;
use App\Http\Controllers\Web\AdminStudentsController;
use App\Http\Controllers\Web\AttendanceReportController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BrandManifestController;
use App\Http\Controllers\Web\KitchenController;
use App\Http\Controllers\Web\PaeExportController;
use App\Http\Controllers\Web\PaeReportController;
use App\Http\Controllers\Web\ParentViewController;
use App\Http\Controllers\Web\PasswordController;
use App\Http\Controllers\Web\RealtimeTokenController;
use App\Http\Controllers\Web\RecyclingReportController;
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

    $user = auth()->user();

    // TASK-037 — kitchen staff land on the kitchen desk (their only one).
    return redirect()->route(match (true) {
        $user->isStudent() => 'student.dashboard',
        $user->isKitchen() => 'kitchen',
        default => 'dashboard',
    });
})->name('home');

// TASK-045 (ADR-065) — the installable-app manifest, rendered from the
// resolved brand (school crest + institution name, or stock Pulse).
// Public: it must answer for the login screen too.
Route::get('/manifest.webmanifest', [BrandManifestController::class, 'show'])
    ->name('brand.manifest');

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

// TASK-030-A (ADR-044) — first-login password rotation. The form lives
// outside the `password.changed` group (it IS the exemption target);
// the update clears the flag and lands on the role's own dashboard.
Route::middleware(['auth'])->group(function () {
    Route::get('/password/change', [PasswordController::class, 'edit'])
        ->name('password.change');

    Route::put('/password/change', [PasswordController::class, 'update'])
        ->name('password.update');
});

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

    // TASK-038 — the staff accounts desk: create admin/teacher/
    // kitchen logins (+ homeroom assignment for teachers) without SQL.
    Route::get('/admin/staff', [AdminStaffController::class, 'page'])
        ->middleware('role:admin')
        ->name('admin.staff');

    // TASK-037 — the settings desk: every safe presence/PAE knob
    // (meal windows, late cutoff, pairing window, account conventions).
    Route::get('/admin/settings', [AdminSettingsController::class, 'page'])
        ->middleware('role:admin')
        ->name('admin.settings');

    // TASK-037 — the PAE reporting desk: daily/monthly reports with
    // graphics, missed meals, flagged attempts, per-student history.
    // Exports (PDF + CSV) ride the same admin wall.
    Route::get('/admin/reports/pae', [PaeReportController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.reports.pae');

    Route::get('/admin/reports/pae/student/{student}', [PaeReportController::class, 'student'])
        ->middleware('role:admin')
        ->name('admin.reports.pae.student');

    Route::get('/admin/reports/pae/export/csv', [PaeExportController::class, 'csv'])
        ->middleware('role:admin')
        ->name('admin.reports.pae.export.csv');

    Route::get('/admin/reports/pae/export/pdf', [PaeExportController::class, 'pdf'])
        ->middleware('role:admin')
        ->name('admin.reports.pae.export.pdf');

    Route::get('/admin/reports/pae/student/{student}/export/csv', [PaeExportController::class, 'studentCsv'])
        ->middleware('role:admin')
        ->name('admin.reports.pae.student.export.csv');

    // The attendance + recycling reporting desks mirror the PAE desk
    // above: general report, per-student report, CSV + PDF exports.
    Route::get('/admin/reports/attendance', [AttendanceReportController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance');

    Route::get('/admin/reports/attendance/student/{student}', [AttendanceReportController::class, 'student'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance.student');

    Route::get('/admin/reports/attendance/export/csv', [AttendanceReportController::class, 'csv'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance.export.csv');

    Route::get('/admin/reports/attendance/export/pdf', [AttendanceReportController::class, 'pdf'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance.export.pdf');

    Route::get('/admin/reports/attendance/student/{student}/export/csv', [AttendanceReportController::class, 'studentCsv'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance.student.export.csv');

    Route::get('/admin/reports/attendance/student/{student}/export/pdf', [AttendanceReportController::class, 'studentPdf'])
        ->middleware('role:admin')
        ->name('admin.reports.attendance.student.export.pdf');

    Route::get('/admin/reports/recycling', [RecyclingReportController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling');

    Route::get('/admin/reports/recycling/student/{student}', [RecyclingReportController::class, 'student'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling.student');

    Route::get('/admin/reports/recycling/export/csv', [RecyclingReportController::class, 'csv'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling.export.csv');

    Route::get('/admin/reports/recycling/export/pdf', [RecyclingReportController::class, 'pdf'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling.export.pdf');

    Route::get('/admin/reports/recycling/student/{student}/export/csv', [RecyclingReportController::class, 'studentCsv'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling.student.export.csv');

    Route::get('/admin/reports/recycling/student/{student}/export/pdf', [RecyclingReportController::class, 'studentPdf'])
        ->middleware('role:admin')
        ->name('admin.reports.recycling.student.export.pdf');

    Route::get('/admin/reports/pae/student/{student}/export/pdf', [PaeExportController::class, 'studentPdf'])
        ->middleware('role:admin')
        ->name('admin.reports.pae.student.export.pdf');

    // TASK-037 — the kitchen meal-service desk (ADR-054): glanceable,
    // realtime accept/reject states for meal-service staff. Kitchen
    // users are RESTRICTED to this workflow (their login lands here
    // and every other desk's role wall 403s them); admins may open it
    // too for verification.
    Route::get('/kitchen', [KitchenController::class, 'page'])
        ->middleware('role:kitchen,admin')
        ->name('kitchen');

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
    // TASK-037: kitchen too — the /kitchen page boots realtime.js the
    // same way and its frames stay name-level (no UIDs, no admin
    // channels — see RealtimeServeCommand::resolveScope).
    Route::get('/realtime/token', [RealtimeTokenController::class, 'issue'])
        ->middleware('role:admin,teacher,student,kitchen')
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

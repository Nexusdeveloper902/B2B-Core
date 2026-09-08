<?php

use App\Http\Controllers\Api\V1\ArmPairingController;
use App\Http\Controllers\Api\V1\CaptureImageController;
use App\Http\Controllers\Api\V1\CardPairingController;
use App\Http\Controllers\Api\V1\CardUnpairController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\NlQueryController;
use App\Http\Controllers\Api\V1\PairingStatusController;
use App\Http\Controllers\Api\V1\ReaderModeController;
use App\Http\Controllers\Api\V1\ReaderSettingsController;
use App\Http\Controllers\Api\V1\RecyclingCaptureController;
use App\Http\Controllers\Api\V1\RecyclingClassificationController;
use App\Http\Controllers\Api\V1\RedemptionController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\TapEventController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {

    /*
    |----------------------------------------------------------------
    | Device-facing endpoints (Hardware Abstraction Principle)
    |----------------------------------------------------------------
    | Stateless. Auth = static Bearer API key per reader. Anything that
    | can make an authenticated HTTP POST works: Postman, curl, tests,
    | future ESP32 firmware — zero backend changes when hardware lands.
    */

    // Phase B — the core loop: tap -> identify -> timestamp -> labeled event.
    Route::middleware('reader.auth')->group(function () {
        Route::post('/events/tap', [TapEventController::class, 'store'])
            ->name('api.v1.events.tap');

        // Phase C — classification + points earn (multipart: event_id, image).
        Route::post('/recycling/classify', [RecyclingClassificationController::class, 'store'])
            ->name('api.v1.recycling.classify');

        // TASK-025 item 2 — the bottle-first flow (spec §3 Case B/§5/§32):
        // an image captured BEFORE any card, held awaiting_card until a
        // card association resolves it. NO classifier call before the
        // association (spec §4 cost gate).
        Route::post('/recycling/capture', [RecyclingCaptureController::class, 'store'])
            ->name('api.v1.recycling.capture');

        Route::post('/recycling/captures/{capture}/associate', [RecyclingCaptureController::class, 'associate'])
            ->name('api.v1.recycling.captures.associate');

        // TASK-010 (firmware TASK-001 Phase E1) — device side of card
        // pairing: pair a freshly scanned card with the pending pairing
        // armed from the dashboard. Same Bearer reader identity as tap.
        Route::post('/admin/cards/pair', [CardPairingController::class, 'store'])
            ->name('api.v1.cards.pair');
    });

    /*
    |----------------------------------------------------------------
    | Dashboard-user endpoints (admin / teacher)
    |----------------------------------------------------------------
    | Session-authenticated via Sanctum's stateful API middleware
    | (same-origin dashboard fetch) or a personal access token.
    */

    // Phase B — reader relabeling (admin-only).
    Route::put('/admin/readers/{reader}/mode', [ReaderModeController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.readers.mode');

    Route::post('/admin/readers/{reader}/mode', [ReaderModeController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin']);

    // TASK-027 — reader management (admin-only): label (name) + active
    // mode in one settings update, backing /admin/readers. The mode-only
    // endpoint above stays untouched (its contract is pinned by tests).
    Route::put('/admin/readers/{reader}', [ReaderSettingsController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.readers.update');

    // TASK-027 — student management (admin-only): create single students
    // and bulk-import a CSV from the /admin/students desk (no more
    // hand-written SQL).
    Route::post('/admin/students', [StudentController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.students.store');

    Route::post('/admin/students/import', [StudentController::class, 'import'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.students.import');

    // TASK-027 — per-card unpair (admin-only, GUI side of ADR-023's
    // freshness semantics: deleting the row makes the credential fresh
    // again; tap events cascade with the card, exactly like cards:unpair).
    Route::delete('/admin/cards/{card}', [CardUnpairController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.cards.unpair');

    // TASK-027 (gap E1) — authorized streaming of a stored capture
    // image (admin-only; images may contain students, so the private
    // disk stays private behind this route).
    Route::get('/admin/captures/{deposit}/image', [CaptureImageController::class, 'show'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.captures.image');

    // TASK-010 (firmware TASK-001 Phase E1) — dashboard side of card
    // pairing: arm a short-lived pending pairing for a student.
    Route::post('/admin/students/{student}/arm-pairing', [ArmPairingController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.students.arm-pairing');

    // TASK-011 — read-only pairing status for the dashboard pairing desk
    // (active window, last completed pairing, recent history).
    Route::get('/admin/pairing/status', [PairingStatusController::class, 'show'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.pairing.status');

    // TASK-025 item 4 — the leaderboard (spec §22/§28): ranking derived
    // from the real points ledger; students also receive their own
    // standing (resolved from their account, never a URL parameter).
    Route::get('/recycling/leaderboard', [LeaderboardController::class, 'show'])
        ->middleware(['auth:sanctum', 'role:admin,teacher,student'])
        ->name('api.v1.recycling.leaderboard');

    // Phase D — redemption (admin or teacher; desk interaction).
    Route::post('/students/{student}/redeem', [RedemptionController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin,teacher'])
        ->name('api.v1.students.redeem');

    // Phase E — natural-language query. TASK-027 — admin AND teacher
    // (the teacher's questions are server-side scoped to their classes;
    // see NlQueryController + StudentScope).
    Route::post('/nl-query', [NlQueryController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin,teacher'])
        ->name('api.v1.nl-query');
});

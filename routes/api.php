<?php

use App\Http\Controllers\Api\V1\ArmPairingController;
use App\Http\Controllers\Api\V1\CardPairingController;
use App\Http\Controllers\Api\V1\NlQueryController;
use App\Http\Controllers\Api\V1\ReaderModeController;
use App\Http\Controllers\Api\V1\RecyclingClassificationController;
use App\Http\Controllers\Api\V1\RedemptionController;
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

    // TASK-010 (firmware TASK-001 Phase E1) — dashboard side of card
    // pairing: arm a short-lived pending pairing for a student.
    Route::post('/admin/students/{student}/arm-pairing', [ArmPairingController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.students.arm-pairing');

    // Phase D — redemption (admin or teacher; desk interaction).
    Route::post('/students/{student}/redeem', [RedemptionController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin,teacher'])
        ->name('api.v1.students.redeem');

    // Phase E — natural-language query (admin-only).
    Route::post('/nl-query', [NlQueryController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.nl-query');
});

<?php

use App\Http\Controllers\Api\V1\ReaderModeController;
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
    });

    // Phase B — reader relabeling (admin-only).
    Route::put('/admin/readers/{reader}/mode', [ReaderModeController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin'])
        ->name('api.v1.readers.mode');

    Route::post('/admin/readers/{reader}/mode', [ReaderModeController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:admin']);
});

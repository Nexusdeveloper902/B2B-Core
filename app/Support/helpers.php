<?php

use App\Services\SettingsService;

/**
 * TASK-037 — global accessor for the runtime settings service (ADR-055).
 *
 * Every consumer of a formerly env-only knob (meal windows, late cutoff,
 * pairing window, student account conventions) resolves through this so
 * admin UI overrides are live everywhere at once.
 */
if (! function_exists('settings')) {
    function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}

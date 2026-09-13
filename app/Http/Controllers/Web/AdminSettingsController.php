<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\Request;

/**
 * TASK-037 — the /admin/settings desk (ADR-055).
 *
 * One bilingual form for every safe presence/PAE environment knob: meal
 * serving windows, the attendance late cutoff, the card-pairing window,
 * and the student account conventions. Writes go through the admin API
 * (SettingsController::update) via the same statefulApi fetch pattern
 * the other desks use — the page itself only renders state.
 */
class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function page(Request $request)
    {
        return view('admin.settings', [
            'settings' => $this->settings->all(),
            'customized' => $this->settings->customizedKeys(),
        ]);
    }
}

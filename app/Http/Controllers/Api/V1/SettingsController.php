<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * TASK-037 — the admin settings surface (ADR-055).
 *
 * GET  /api/v1/admin/settings — every canonical setting with its
 *                              effective value (+ which keys are
 *                              customized).
 * PUT  /api/v1/admin/settings — validate + persist a partial batch of
 *                              overrides; runtime behavior picks them up
 *                              on the very next request.
 *
 * Admin-only. The canonical key registry, cross-field validation (meal
 * windows must not overlap) and the config fallback chain live in
 * SettingsService — one truth for the API, the /admin/settings desk and
 * the runtime consumers.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'settings' => $this->settings->all(),
            'customized' => $this->settings->customizedKeys(),
            'meal_windows' => $this->settings->mealWindows(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->input('settings');

        if (! is_array($values) || $values === []) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.settings_empty'),
            ], 422);
        }

        try {
            $settings = $this->settings->setMany($values);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.settings_invalid'),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'settings' => $settings,
            'customized' => $this->settings->customizedKeys(),
            'message' => __('api.settings_saved'),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Branding\BrandResolver;
use Illuminate\Http\JsonResponse;

/**
 * TASK-045 (ADR-065) — the installable-app manifest, rendered from the
 * resolved brand instead of the static public/manifest.webmanifest.
 *
 * Same document, same keys, same values for default Pulse (the static
 * file stays on disk as the no-PHP fallback); a branded school installs
 * with its own name and crest. Deliberately NOT one manifest per school
 * shipped to every browser: each device fetches exactly the one its own
 * account resolves to.
 */
class BrandManifestController extends Controller
{
    public function show(BrandResolver $brands): JsonResponse
    {
        $brand = $brands->current();

        return response()->json([
            'name' => $brand->name(),
            'short_name' => (string) __('app.app_name'),
            'description' => (string) __('app.footer_note'),
            'lang' => app()->getLocale(),
            'start_url' => url('/'),
            'display' => 'standalone',
            'background_color' => $brand->themeColor(),
            'theme_color' => $brand->themeColor(),
            'icons' => $brand->manifestIcons(),
        ], 200, [
            'Content-Type' => 'application/manifest+json',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

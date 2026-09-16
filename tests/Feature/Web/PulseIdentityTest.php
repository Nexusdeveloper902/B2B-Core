<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Productization pass (2026-09-12) — the Pulse identity contract:
 * every page carries the Pulse name + real brand mark, the favicon/PWA
 * suite ships and is linked, the toast system is loaded app-wide, and
 * 419/429 render inside the branded shell like their siblings.
 */
class PulseIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_shell_surface_is_branded_pulse(): void
    {
        $login = $this->get('/login')->getContent();

        // The product name and the REAL mark (brand suite knockout),
        // with the placeholder tile and the legacy wordmark gone.
        $this->assertMatchesRegularExpression('/<title>Sign in — Pulse<\/title>|— Pulse<\/title>/', $login);
        $this->assertStringContainsString('brand/mark-96.png', $login);
        $this->assertStringNotContainsString('Presence<em>', $login);
        $this->assertStringNotContainsString('wordmark-tap', $login);
    }

    #[Test]
    public function the_head_carries_favicons_manifest_and_social_metadata(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('rel="icon"', $html);
        $this->assertStringContainsString('favicon.ico', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('name="theme-color"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
    }

    #[Test]
    public function the_brand_asset_suite_ships_and_resolves(): void
    {
        // The suite derives from the REAL Pulse logo (B2B-Logo-Suite);
        // a 0-byte favicon or a missing icon is a broken brand, not a
        // cosmetic miss.
        foreach ([
            'favicon.ico',
            'brand/mark-96.png',
            'brand/mark-cream-96.png',
            'brand/favicon-16.png',
            'brand/favicon-32.png',
            'brand/apple-touch-icon.png',
            'brand/icon-192.png',
            'brand/icon-512.png',
            'brand/icon-maskable-192.png',
            'brand/icon-maskable-512.png',
            'brand/og-image.png',
        ] as $asset) {
            $this->assertFileExists(public_path($asset), "brand asset missing: {$asset}");
            $this->assertGreaterThan(0, filesize(public_path($asset)), "brand asset is empty: {$asset}");
        }

        // TASK-045 (ADR-065): the manifest is RENDERED per brand now
        // (GET /manifest.webmanifest). The static file was deleted on
        // purpose — a real file in public/ is served by the web server
        // before PHP ever sees the request, so keeping it would have
        // silently shadowed the route in production while tests, which
        // bypass the web server, stayed green.
        $this->assertFileDoesNotExist(public_path('manifest.webmanifest'));

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $this->assertSame('Pulse', $manifest['name']);
        $this->assertNotEmpty($manifest['icons']);
    }

    #[Test]
    public function the_toast_system_is_loaded_app_wide(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('js/toast.js', $html);
        $this->assertStringContainsString('PulseToastLabels', $html);

        $css = file_get_contents(public_path('css/app.css'));
        foreach (['.toast-region', '.toast-success', '.toast-error', '.toast-warning', '.toast-info', '.toast-dismiss'] as $rule) {
            $this->assertStringContainsString($rule, $css, "toast CSS rule missing: {$rule}");
        }
        $this->assertFileExists(public_path('js/toast.js'));
    }

    #[Test]
    public function the_desks_acknowledge_actions_with_toasts(): void
    {
        // The desk scripts must call the toast system on their action
        // paths (success AND the previously-silent network catches).
        foreach ([
            'admin/students.blade.php',
            'admin/readers.blade.php',
            'admin/pairing.blade.php',
            'admin/dashboard.blade.php',
            'teacher/dashboard.blade.php',
        ] as $view) {
            $body = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('PulseToast', $body, "{$view} never acknowledges actions");
        }
    }

    #[Test]
    public function error_pages_419_and_429_render_in_the_branded_shell(): void
    {
        // 419 (stale form / CSRF mismatch) and 429 (throttled) previously
        // fell through to Laravel's bare framework page. The framework
        // maps these codes to resources/views/errors/{code}.blade.php by
        // convention — the app-owned contract is the view itself, which
        // must render inside the shared shell, localized, with a way out.
        foreach ([419, 429] as $code) {
            $this->assertFileExists(resource_path("views/errors/{$code}.blade.php"));
            $rendered = view("errors.{$code}", ['code' => $code])->render();
            $this->assertStringContainsString(">{$code}</p>", $rendered);
            $this->assertStringContainsString('class="topbar"', $rendered, "{$code} must render in the app shell");
            $this->assertStringContainsString('btn btn-primary', $rendered, "{$code} must offer a way out");
        }
    }

    #[Test]
    public function the_error_pages_are_bilingual(): void
    {
        $this->app->setLocale('es');

        $rendered = view('errors.419', ['code' => 419])->render();
        $this->assertStringContainsString('Sesión expirada', $rendered);

        $rendered = view('errors.429', ['code' => 429])->render();
        $this->assertStringContainsString('Demasiadas solicitudes', $rendered);
    }
}

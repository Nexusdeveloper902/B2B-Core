<?php

namespace Tests\Feature\Web;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use App\Support\Branding\BrandResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-045 (ADR-065) — school branding.
 *
 * The contract under test is the chain
 *
 *     current account → school → branding configuration
 *
 * and, just as importantly, its FLOOR: an account with no school, an
 * unknown branding profile or no account at all renders exactly the
 * Pulse shell that shipped before this layer existed.
 */
class SchoolBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL = 'IE Concejo de Sabaneta J.M.C.B';

    private const BRAND_PRIMARY = '#80193c';

    private const CREST = 'brand/schools/ie-concejo-de-sabaneta/crest-96.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function brandedSchool(): School
    {
        // The demo fixture already provisions this exact school, and
        // provision is idempotent — so this returns the same row
        // instead of colliding on the unique slug.
        return School::provision('IE Concejo de Sabaneta J.M.C.B', 'ie-concejo-de-sabaneta', 'ie-concejo-de-sabaneta');
    }

    private function adminOf(?School $school): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin->value,
            'school_id' => $school?->id,
        ]);
    }

    // ----------------------------------------------------------------
    // Resolution
    // ----------------------------------------------------------------

    #[Test]
    public function an_account_of_the_branded_school_receives_the_school_branding(): void
    {
        $brand = app(BrandResolver::class)->for($this->brandedSchool());

        $this->assertFalse($brand->isDefault());
        $this->assertSame('ie-concejo-de-sabaneta', $brand->key());
        $this->assertSame(self::SCHOOL, $brand->name());
        $this->assertSame(self::BRAND_PRIMARY, $brand->primaryColor());
        $this->assertSame(self::CREST, $brand->logoSrc());
    }

    #[Test]
    public function an_account_without_a_school_receives_normal_pulse_branding(): void
    {
        $brand = app(BrandResolver::class)->for(null);

        $this->assertTrue($brand->isDefault());
        $this->assertSame('pulse', $brand->key());
        $this->assertSame('Pulse', $brand->name());
        $this->assertSame('#242423', $brand->primaryColor());
        $this->assertSame('brand/mark-96.png', $brand->logoSrc());
        // Nothing is injected at all: the stylesheet IS the Pulse palette.
        $this->assertSame('', $brand->styleBlock());
    }

    #[Test]
    public function an_unknown_future_school_falls_back_safely_to_pulse(): void
    {
        $school = School::factory()->withUnknownBrand()->create();

        $brand = app(BrandResolver::class)->for($school);

        $this->assertTrue($brand->isDefault(), 'a profile that has not shipped must never break the shell');
        $this->assertSame('#242423', $brand->primaryColor());
    }

    #[Test]
    public function a_school_may_exist_without_naming_any_branding_profile(): void
    {
        $school = School::factory()->create(['brand_key' => null]);

        $this->assertTrue(app(BrandResolver::class)->for($school)->isDefault());
    }

    // ----------------------------------------------------------------
    // The rendered shell
    // ----------------------------------------------------------------

    #[Test]
    public function the_branded_shell_ships_the_brand_tokens_before_first_paint(): void
    {
        $html = $this->actingAs($this->adminOf($this->brandedSchool()))->get('/admin')->getContent();

        $this->assertStringContainsString('<style id="brand-theme">', $html);
        $this->assertStringContainsString('--brand-primary:'.self::BRAND_PRIMARY, $html);
        $this->assertStringContainsString('--brand-primary-hover:#9a1e48', $html);
        $this->assertStringContainsString('--brand-primary-contrast:#ffffff', $html);

        // The override must come AFTER the stylesheet that defines the
        // defaults, or the cascade would keep the Pulse values.
        $this->assertGreaterThan(
            strpos($html, 'css/tokens.css'),
            strpos($html, 'brand-theme'),
            'the brand block must override tokens.css, not be overridden by it',
        );
        // And it must be in the HEAD — an override that lands in the body
        // is a repaint, which is exactly the flash this layer prevents.
        $this->assertLessThan(strpos($html, '<body>'), strpos($html, 'brand-theme'));
    }

    #[Test]
    public function the_branded_shell_uses_the_official_school_crest_and_names_the_institution(): void
    {
        $html = $this->actingAs($this->adminOf($this->brandedSchool()))->get('/admin')->getContent();

        $this->assertStringContainsString(self::CREST, $html);
        $this->assertStringNotContainsString('brand/mark-96.png', $html, 'the Pulse mark is replaced, not doubled');
        // Explicit dimensions on every mark: no layout shift when the
        // crest loads, and a square asset can never render stretched.
        $this->assertMatchesRegularExpression('/crest-96\.png[^>]*width="34"[^>]*height="34"/', $html);
        // Pulse keeps its name; the school is named beside it.
        $this->assertStringContainsString('Pulse', $html);
        $this->assertStringContainsString('IE Concejo de Sabaneta', $html);
        $this->assertStringContainsString('Branded for '.self::SCHOOL, $html);
    }

    #[Test]
    public function every_authenticated_shell_wears_the_account_brand(): void
    {
        $admin = $this->adminOf($this->brandedSchool());

        // The forced first-login rotation screen is authenticated, so it
        // resolves from the account like every other page — it was the
        // one shell that still hard-coded the Pulse mark.
        $admin->forceFill(['must_change_password' => true])->save();

        $html = $this->actingAs($admin)->get('/password/change')->getContent();

        $this->assertStringContainsString(self::CREST, $html);
        $this->assertStringContainsString(self::SCHOOL, $html);
        $this->assertStringNotContainsString('brand/mark-96.png', $html);
    }

    #[Test]
    public function the_branded_shell_swaps_the_browser_and_social_asset_set(): void
    {
        $html = $this->actingAs($this->adminOf($this->brandedSchool()))->get('/admin')->getContent();

        $this->assertStringContainsString('brand/schools/ie-concejo-de-sabaneta/favicon-32.png', $html);
        $this->assertStringContainsString('brand/schools/ie-concejo-de-sabaneta/favicon-16.png', $html);
        $this->assertStringContainsString('brand/schools/ie-concejo-de-sabaneta/apple-touch-icon.png', $html);
        $this->assertStringContainsString('brand/schools/ie-concejo-de-sabaneta/og-image.png', $html);
        $this->assertStringContainsString('<title>'.__('app.admin_dashboard').' — '.self::SCHOOL, $html);
    }

    #[Test]
    public function every_branding_asset_referenced_by_a_profile_actually_exists(): void
    {
        foreach (array_keys((array) config('branding.brands')) as $key) {
            $brand = app(BrandResolver::class)->forKey($key);

            $paths = [$brand->logoSrc(), $brand->ogImage()];
            foreach (['ico', 'png32', 'png16', 'apple'] as $icon) {
                $paths[] = $brand->icon($icon);
            }
            foreach ($brand->manifestIcons() as $icon) {
                $paths[] = ltrim(parse_url($icon['src'], PHP_URL_PATH) ?: '', '/');
            }

            foreach (array_filter($paths) as $path) {
                $this->assertFileExists(public_path($path), "brand [{$key}] references a missing asset: {$path}");
            }
        }
    }

    #[Test]
    public function the_installable_manifest_follows_the_account(): void
    {
        // Both accounts are built BEFORE either signs in: creation
        // inheritance is real, so an account minted while acting as a
        // school admin would join that admin's school (which is the
        // point of §15 — but not what this test is about).
        $branded = $this->adminOf($this->brandedSchool());
        $plain = $this->adminOf(null);

        $this->actingAs($branded)
            ->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', self::SCHOOL)
            ->assertJsonPath('short_name', 'Pulse');

        $this->actingAs($plain)
            ->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Pulse');
    }

    // ----------------------------------------------------------------
    // Preservation: the unbranded shell must not move
    // ----------------------------------------------------------------

    #[Test]
    public function an_unbranded_account_renders_the_untouched_pulse_shell(): void
    {
        $html = $this->actingAs($this->adminOf(null))->get('/admin')->getContent();

        $this->assertStringNotContainsString('brand-theme', $html);
        $this->assertStringNotContainsString('wordmark-school', $html);
        $this->assertStringNotContainsString('footer-school', $html);
        $this->assertStringContainsString('brand/mark-96.png', $html);
        $this->assertStringContainsString('brand/favicon-32.png', $html);
        $this->assertStringContainsString('<title>'.__('app.admin_dashboard').' — Pulse', $html);
    }

    // ----------------------------------------------------------------
    // Session hygiene: branding must not survive an identity change
    // ----------------------------------------------------------------

    #[Test]
    public function switching_between_differently_branded_accounts_leaks_no_branding(): void
    {
        $school = $this->brandedSchool();
        $schoolAdmin = $this->adminOf($school);
        $pulseAdmin = $this->adminOf(null);

        $this->post('/login', ['email' => $schoolAdmin->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertStringContainsString(self::BRAND_PRIMARY, $this->get('/admin')->getContent());

        $this->post('/logout')->assertRedirect(route('login'));

        $this->post('/login', ['email' => $pulseAdmin->email, 'password' => 'password'])
            ->assertRedirect();

        $html = $this->get('/admin')->getContent();
        $this->assertStringNotContainsString(self::BRAND_PRIMARY, $html);
        $this->assertStringNotContainsString(self::CREST, $html);
        $this->assertStringContainsString('brand/mark-96.png', $html);
    }

    #[Test]
    public function a_stale_device_brand_never_overrides_the_authenticated_account(): void
    {
        // The device remembers a school (its shared login screen), then a
        // Pulse account signs in on it. The ACCOUNT always wins.
        $this->brandedSchool();

        $html = $this->withCookie(BrandResolver::COOKIE, 'ie-concejo-de-sabaneta')
            ->actingAs($this->adminOf(null))
            ->get('/admin')
            ->getContent();

        $this->assertStringNotContainsString(self::BRAND_PRIMARY, $html);
        $this->assertStringContainsString('brand/mark-96.png', $html);
    }

    #[Test]
    public function the_login_screen_wears_the_brand_this_device_last_signed_in_with(): void
    {
        // A guest has no account to resolve from; the device's own memory
        // is what stops a school's shared tablet from flashing Pulse and
        // repainting after sign-in.
        $html = $this->withCookie(BrandResolver::COOKIE, 'ie-concejo-de-sabaneta')
            ->get('/login')
            ->getContent();

        $this->assertStringContainsString(self::CREST, $html);
        $this->assertStringContainsString(self::SCHOOL, $html);
    }

    #[Test]
    public function a_device_with_no_memory_shows_the_stock_pulse_login(): void
    {
        $plain = $this->get('/login')->getContent();

        $this->assertStringContainsString('brand/mark-96.png', $plain);
        $this->assertStringNotContainsString('brand-theme', $plain);
        $this->assertStringNotContainsString('auth-band-school', $plain);
    }

    #[Test]
    public function signing_in_with_an_unbranded_account_clears_the_device_memory(): void
    {
        $this->brandedSchool();
        $pulseAdmin = $this->adminOf(null);

        $this->withCookie(BrandResolver::COOKIE, 'ie-concejo-de-sabaneta')
            ->post('/login', ['email' => $pulseAdmin->email, 'password' => 'password'])
            ->assertRedirect()
            ->assertCookieExpired(BrandResolver::COOKIE);
    }

    #[Test]
    public function the_brand_layer_never_touches_semantic_or_surface_colors(): void
    {
        $brand = app(BrandResolver::class)->forKey('ie-concejo-de-sabaneta');

        foreach (array_keys($brand->tokens()) as $token) {
            $this->assertStringStartsWith('--brand-', $token, 'a profile may only move brand-layer tokens');
        }

        // The gold points/eco accent, the error family and the surface
        // ground stay out of every profile's reach by construction.
        foreach (['--tertiary-fixed', '--error', '--surface', '--background', '--on-surface'] as $offLimits) {
            $this->assertArrayNotHasKey($offLimits, $brand->tokens());
        }
    }

    #[Test]
    public function brand_colors_keep_readable_contrast(): void
    {
        $brand = app(BrandResolver::class)->forKey('ie-concejo-de-sabaneta');
        $tokens = $brand->tokens();

        // WCAG AA for normal text is 4.5:1. Each pair below is one the
        // shell actually renders: a label on the action color, and the
        // action color on the two grounds it sits on.
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary-contrast'], $tokens['--brand-primary']));
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary-contrast'], $tokens['--brand-primary-hover']));
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary-muted'], $tokens['--brand-primary']));
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary-on-muted'], $tokens['--brand-primary-muted']));
        // The action color on the Pulse cream ground and on white cards.
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary'], '#e8eddf'));
        $this->assertGreaterThan(4.5, $this->contrast($tokens['--brand-primary'], '#ffffff'));
        // The gold points accent still reads on the brand color, which is
        // why it is allowed to stay brand-independent.
        $this->assertGreaterThan(4.5, $this->contrast('#f5cb5c', $tokens['--brand-primary']));
    }

    private function contrast(string $a, string $b): float
    {
        $l = fn (string $hex): float => (function (array $rgb): float {
            $channel = fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $channel($rgb[0] / 255) + 0.7152 * $channel($rgb[1] / 255) + 0.0722 * $channel($rgb[2] / 255);
        })(array_map('hexdec', str_split(ltrim($hex, '#'), 2)));

        $la = $l($a);
        $lb = $l($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}

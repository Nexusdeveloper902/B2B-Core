<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix 3) — product surface: project presence in the footer
 * and the last hardcoded unit strings, in both languages.
 */
class ProductizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    #[Test]
    public function the_footer_links_the_project_instagram(): void
    {
        // The owner's channel as a functional link — guests see it on
        // the login page, staff on every dashboard (it lives in the
        // shared shell).
        $this->get('/login')
            ->assertOk()
            ->assertSee('https://www.instagram.com/puls.e1681', false)
            ->assertSee('@puls.e1681', false)
            ->assertSee('Follow the project', false);

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('https://www.instagram.com/puls.e1681', false);
    }

    #[Test]
    public function the_footer_link_is_bilingual(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get('/login')
            ->assertOk()
            ->assertSee('https://www.instagram.com/puls.e1681', false)
            ->assertSee('Sigue el proyecto', false);
    }

    #[Test]
    public function the_ecostation_points_unit_is_localized_not_hardcoded(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/ecostation')
            ->assertOk()
            ->getContent();

        // The unit renders (localized key, PTS in both languages today).
        $this->assertStringContainsString(__('app.points_unit'), $html);
        // Live JS paths go through the labels map, never a literal.
        $this->assertStringContainsString('labels.pointsUnit', $html);

        // Source-level honesty: no hardcoded unit left in the view or
        // the live JS (rendered output legitimately contains "NN PTS" —
        // the assertion belongs on the sources, linter-style).
        $source = (string) file_get_contents(resource_path('views/admin/ecostation.blade.php'));
        $this->assertStringNotContainsString('PTS', $source);

        foreach (glob(public_path('js/*.js')) ?: [] as $script) {
            $this->assertStringNotContainsString('PTS', (string) file_get_contents($script), basename((string) $script).' must not hardcode the unit');
        }
    }

    #[Test]
    public function the_pilot_reset_flag_is_wired_and_documented(): void
    {
        // Same testing style as ScriptSuiteTest: the workflow is a
        // script flag, so pin the flag + its bilingual docs.
        $reset = (string) file_get_contents(base_path('scripts/reset.sh'));
        $this->assertStringContainsString('--pilot', $reset);
        $this->assertStringContainsString('PilotSeeder', $reset);

        foreach (['docs/SCRIPTS.md', 'docs/SCRIPTS.es.md'] as $doc) {
            $this->assertStringContainsString('--pilot', (string) file_get_contents(base_path($doc)), "{$doc} must document the pilot flag");
        }
    }
}

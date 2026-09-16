<?php

namespace Tests\Feature\Web;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-046 — responsive layout regressions.
 *
 * Every rule pinned here fixed a measured failure: the panels were
 * rendered in a real browser at 15 viewport widths (320 → 1600) and
 * checked for horizontal page scroll and for elements escaping the
 * viewport. These are the CSS facts that made them pass, so a future
 * edit that quietly reverts one fails here instead of on a phone.
 */
class ResponsiveLayoutTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    #[Test]
    public function layout_items_may_shrink_below_their_content(): void
    {
        // The root cause of every horizontal-page-scroll bug found: flex
        // and grid items default to `min-width: auto`, so a nested
        // `overflow-x: auto` scroller can never actually shrink and the
        // PAGE scrolls sideways instead of the strip.
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.grid-2 > \*,[^{]*\.filterbar > \*,[^{]*\.settings-row > \* \{ min-width: 0; \}/s',
            $css,
            'the layout containers must let their items shrink',
        );
        $this->assertMatchesRegularExpression('/\.pills \{[^}]*min-width: 0;/', $css);
        $this->assertMatchesRegularExpression('/\.filterbar \{\s*min-width: 0;/', $css);
    }

    #[Test]
    public function the_mobile_filter_bar_is_a_single_column_with_an_inline_search(): void
    {
        $css = $this->css();

        // A column flex container that still WRAPS is a multi-column
        // container: its line takes the cross size of the widest item, so
        // the pill row sized the whole page.
        $this->assertMatchesRegularExpression(
            '/\.filterbar \{ flex-direction: column;[^}]*flex-wrap: nowrap; \}/',
            $css,
        );

        // `flex-basis` sizes the MAIN axis, so the desktop `1 1 320px`
        // became a 320px-TALL search box once the bar turned vertical.
        $this->assertMatchesRegularExpression(
            '/\.filterbar \.searchbox,\s*\.filterbar--flush \.searchbox \{ flex: 0 0 auto; \}/',
            $css,
        );

        // The pills themselves become a scroll strip whose items keep
        // their size.
        $this->assertMatchesRegularExpression('/\.filterbar \.pills \{[^}]*overflow-x: auto;/', $css);
        $this->assertStringContainsString('.filterbar .pills > * { flex-shrink: 0; }', $css);
    }

    #[Test]
    public function the_topbar_nav_shrinks_instead_of_pushing_the_tools_off_screen(): void
    {
        $css = $this->css();

        // Short navs scroll their own strip instead of pushing logout
        // and the language switch past the right edge of the viewport.
        $this->assertMatchesRegularExpression('/\.topbar-in \{[^}]*min-width: 0;/s', $css);
        $this->assertMatchesRegularExpression('/\.topnav \{[^}]*min-width: 0;[^}]*overflow-x: auto;/s', $css);
        $this->assertMatchesRegularExpression('/\.topnav a \{\s*flex-shrink: 0;/', $css);
        $this->assertStringContainsString('.topbar-tools { flex-shrink: 0; }', $css);
    }

    #[Test]
    public function a_long_topbar_nav_collapses_into_the_hamburger(): void
    {
        // Measured in a real browser: the nine-link admin strip needs
        // ~950px in EN and ~1150px in ES, which never fits the 1360px
        // shell beside the wordmark and the tools — trailing links sat
        // clipped past the strip with no scroll affordance. A nav with
        // eight or more links skips the strip at every width instead.
        $css = $this->css();

        $this->assertStringContainsString(
            '.topbar-in:has(.topnav a:nth-child(8)) .topnav { display: none; }',
            $css,
        );
        $this->assertStringContainsString(
            '.topbar-in:has(.topnav a:nth-child(8)) .mobilenav { display: block; }',
            $css,
        );
    }

    #[Test]
    public function the_report_kpi_strip_has_a_layout_at_every_width(): void
    {
        $css = $this->css();

        // .stat-row (both PAE desks) had no rule at all — its tiles
        // stacked full-width on desktop as well as on a phone.
        $this->assertMatchesRegularExpression(
            '/\.stat-row \{ display: grid; grid-template-columns: repeat\(auto-fit, minmax\(160px, 1fr\)\);/',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.stat-row-compact \{ grid-template-columns: repeat\(auto-fit, minmax\(120px, 1fr\)\); \}/',
            $css,
        );
    }

    #[Test]
    public function the_table_edge_bleed_belongs_to_panels_only(): void
    {
        $css = $this->css();

        // The negative margin cancels a PANEL's padding. Outside a panel
        // it simply pushed the table past the shell gutter (the
        // per-student PAE report, 621–699px).
        $this->assertStringContainsString('.ledger-wrap { overflow-x: auto; }', $css);
        $this->assertStringContainsString('.panel .ledger-wrap { margin-inline: calc(var(--sp-lg) * -1); }', $css);
        $this->assertStringContainsString('.panel .ledger-wrap { margin-inline: 0; }', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/^\.ledger-wrap \{[^}]*margin-inline: calc/m',
            $css,
            'the bleed must not apply to a bare .ledger-wrap',
        );
    }

    #[Test]
    public function button_toolbars_keep_their_intrinsic_width_on_phones(): void
    {
        $css = $this->css();

        // Below 620px every .btn goes full-width, which is right for a
        // form action and wrong for a five-button export toolbar.
        $this->assertStringContainsString('.report-exports .btn, .btn-small { width: auto; }', $css);
    }

    #[Test]
    public function the_school_tag_only_appears_when_the_topbar_has_room(): void
    {
        // TASK-045's "branded for <school>" chip is the first thing
        // dropped when the row gets tight, so it can never be the reason
        // the nav wraps or the tools overflow.
        $this->assertStringContainsString(
            '@media (max-width: 1400px) { .wordmark-school { display: none; } }',
            $this->css(),
        );
    }
}

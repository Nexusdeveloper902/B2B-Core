<?php

namespace Tests\Feature\Web;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_teacher_dashboard_lists_students_with_today_status(): void
    {
        config(['presence.late_cutoff' => '08:15']);

        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);
        PresenceEvent::create([
            'card_id' => $this->cardOf('Carlos Pérez')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(9, 10),
        ]);

        $teacher = User::where('role', 'teacher')->firstOrFail();

        $response = $this->actingAs($teacher)->get('/teacher');

        $response->assertOk()
            ->assertSee('Maria González')
            ->assertSee('Carlos Pérez')
            ->assertSee('Ana Martínez')
            ->assertSeeText('Present')
            ->assertSeeText('Late')
            ->assertSeeText('Absent')
            ->assertSee('08:15'); // late cutoff note
    }

    #[Test]
    public function the_teacher_dashboard_only_shows_the_teachers_own_classes(): void
    {
        $otherClass = SchoolClass::create(['name' => '6° A']);
        $other = Student::create([
            'name' => 'Federico Lejano', 'grade' => '6°', 'class_id' => $otherClass->id,
        ]);
        $other->cards()->create(['credential_uid' => 'REMOTE0001']);

        $teacher = User::where('role', 'teacher')->firstOrFail();

        $this->actingAs($teacher)->get('/teacher')
            ->assertOk()
            ->assertSee('Maria González')
            ->assertDontSee('Federico Lejano');
    }

    #[Test]
    public function the_admin_dashboard_shows_school_wide_stats_and_reader_controls(): void
    {
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now(),
        ]);
        PresenceEvent::create([
            'card_id' => $this->cardOf('Carlos Pérez')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'PAE_BREAKFAST',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->user('admin'))->get('/admin');

        $response->assertOk()
            ->assertSee('Demo Reader — Classroom/PAE')
            ->assertSee('Demo Reader — Recycling')
            ->assertSee('CLASS_ATTENDANCE')
            ->assertSee('PAE_BREAKFAST')
            ->assertSeeText('1'); // attendance + pae counts
    }

    #[Test]
    public function the_admin_dashboard_warns_when_the_nl_query_is_not_configured(): void
    {
        config(['recycling.nl_query.api_key' => null]);

        $this->actingAs($this->user('admin'))
            ->get('/admin')
            ->assertOk()
            ->assertSeeText('DEEPSEEK_API_KEY');
    }

    #[Test]
    public function the_parent_view_renders_a_student_timeline(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now()->subHour(),
        ]);
        RecyclingDeposit::create([
            'event_id' => $event->id, 'material_class' => 'plastic', 'confidence' => 0.9, 'points_awarded' => 10,
        ]);

        $response = $this->actingAs($this->user('admin'))
            ->get("/parent/students/{$student->id}");

        $response->assertOk()
            ->assertSee('Maria González')
            ->assertSee('RECYCLING_DEPOSIT')
            ->assertSee('plastic');
    }

    #[Test]
    public function the_locale_switcher_changes_the_language_of_the_whole_ui(): void
    {
        $teacher = $this->user('teacher');

        // Spanish.
        $this->actingAs($teacher)->get('/locale/es');
        $es = $this->actingAs($teacher)->get('/teacher');
        $es->assertOk()
            ->assertSeeText('Panel del Profesor')
            ->assertSeeText('Asistencia de hoy')
            ->assertSeeText('Ausente')
            ->assertSeeText('Tarde');

        // Back to English.
        $this->actingAs($teacher)->get('/locale/en');
        $this->actingAs($teacher)->get('/teacher')
            ->assertOk()
            ->assertSeeText('Teacher Dashboard')
            ->assertSeeText('Absent')
            ->assertSeeText('Late');
    }

    #[Test]
    public function the_admin_dashboard_renders_the_live_feed_panel_server_side(): void
    {
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $response = $this->actingAs($this->user('admin'))->get('/admin');

        // SSR-first: the panel works with the realtime server DOWN —
        // the initial rows are server-rendered from RealtimeFeed.
        $response->assertOk()
            ->assertSee('Live activity')
            ->assertSee('Maria González')
            ->assertSee('CLASS_ATTENDANCE')
            ->assertSee('id="live-list"', false)
            ->assertSee('id="live-badge"', false)
            ->assertSee('data-realtime=', false)
            ->assertSee('js/realtime.js', false)
            ->assertSeeText('Connecting…');
    }

    #[Test]
    public function the_live_feed_shows_an_honest_waiting_state_with_no_events(): void
    {
        $this->actingAs($this->user('admin'))
            ->get('/admin')
            ->assertOk()
            ->assertSeeText('Waiting for the first tap…');
    }

    #[Test]
    public function the_teacher_dashboard_marks_attendance_rows_for_live_updates(): void
    {
        $response = $this->actingAs($this->user('teacher'))->get('/teacher');

        $response->assertOk()
            ->assertSee('Live activity')
            ->assertSee('data-student-row=', false)
            ->assertSee('js-tap-status', false)
            ->assertSee('js-tap-time', false)
            ->assertSee('data-cutoff=', false)
            ->assertSee('js/realtime.js', false);
    }

    #[Test]
    public function the_live_feed_panel_translates_to_spanish(): void
    {
        $teacher = $this->user('teacher');

        $this->actingAs($teacher)->get('/locale/es');
        $this->actingAs($teacher)->get('/teacher')
            ->assertOk()
            ->assertSeeText('Actividad en vivo')
            ->assertSeeText('Esperando el primer toque…')
            ->assertSeeText('Conectando…');
    }

    #[Test]
    public function the_live_feed_bootstrap_json_survives_html_attribute_escaping(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $html = $this->actingAs($admin)->get('/admin')->getContent();

        // The bootstrap rides a data-* ATTRIBUTE: it must be the escaped
        // echo (raw JSON quotes would terminate the attribute at the
        // first " — the live bench caught exactly that truncation).
        $this->assertMatchesRegularExpression('/data-realtime="([^"]*)"/', $html);
        preg_match('/data-realtime="([^"]*)"/', $html, $match);

        $boot = json_decode(html_entity_decode($match[1] ?? ''), true);

        $this->assertIsArray($boot, 'the data-realtime attribute must decode to JSON');
        $this->assertSame((int) $admin->id, RealtimeToken::verify($boot['token'] ?? null));
        $this->assertSame((int) config('realtime.port'), $boot['port']);
        $this->assertArrayHasKey('strings', $boot);
        $this->assertArrayHasKey('state_live', $boot['strings']);
    }

    #[Test]
    public function the_live_feed_bootstrap_carries_the_relative_time_strings(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $html = $this->actingAs($admin)->get('/admin')->getContent();

        // TASK-017: live-arrived rows age from "just now" to ":n min ago"
        // — the strings must ride the bootstrap in the page's locale.
        $this->assertMatchesRegularExpression('/data-realtime="([^"]*)"/', $html);
        preg_match('/data-realtime="([^"]*)"/', $html, $match);
        $boot = json_decode(html_entity_decode($match[1] ?? ''), true);

        $this->assertIsArray($boot);
        $this->assertArrayHasKey('rel_now', $boot['strings']);
        $this->assertArrayHasKey('rel_min', $boot['strings']);
        $this->assertSame('just now', $boot['strings']['rel_now']);
    }

    #[Test]
    public function the_admin_dashboard_renders_the_hero_kpi_and_soft_cards(): void
    {
        $html = $this->actingAs($this->user('admin'))->get('/admin')->getContent();

        // TASK-026 (Datum, ADR-036) — hero tile, KPI icon glyphs, the
        // 5-KPI strip; icons ride the self-hosted Material Symbols font.
        $this->assertStringContainsString('class="stat-strip"', $html);
        $this->assertStringContainsString('kpi-icon', $html);
        $this->assertStringContainsString('material-symbols-outlined', $html);
        $this->assertStringContainsString('how_to_reg', $html);
        // the attendance hero carries the distinct larger-value rule
        $this->assertMatchesRegularExpression(
            '/\.stat-strip \.stat:first-child[^}]*font-size: 44px;/',
            file_get_contents(public_path('css/app.css')),
        );
    }

    #[Test]
    public function the_live_feed_rows_carry_avatars_and_event_chips(): void
    {
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $html = $this->actingAs($this->user('admin'))->get('/admin')->getContent();

        // TASK-017 — SSR rows and JS rows share one shape: initials
        // avatar + event chip whose tone mapping lives in CSS only.
        $this->assertStringContainsString('class="avatar"', $html);
        $this->assertStringContainsString('>MG</span>', $html);
        $this->assertStringContainsString('class="live-chip"', $html);
        $this->assertStringContainsString('data-event-type="CLASS_ATTENDANCE"', $html);
        // the CSS owns the chip tone mapping (one source of truth)
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('.live-chip[data-event-type^="PAE_"]', $css);
        $this->assertStringContainsString('.live-chip[data-event-type^="RECYCLING_"]', $css);
    }

    #[Test]
    public function the_teacher_dashboard_renders_class_summary_chips(): void
    {
        $html = $this->actingAs($this->user('teacher'))->get('/teacher')->getContent();

        // TASK-017 — counts answer "who's here?" before any table scan.
        // Demo seed: 4 students, no events → 4 absent, 0 present, 0 late.
        $this->assertStringContainsString('sum-chips', $html);
        $this->assertStringContainsString('sum-chip-present', $html);
        $this->assertStringContainsString('sum-chip-late', $html);
        $this->assertStringContainsString('sum-chip-absent', $html);
        $this->assertMatchesRegularExpression('/sum-chip-present[^<]*Present 0/', $html);
        $this->assertMatchesRegularExpression('/sum-chip-late[^<]*Late 0/', $html);
        $this->assertMatchesRegularExpression('/sum-chip-absent[^<]*Absent 4/', $html);
    }

    #[Test]
    public function the_dashboards_stack_their_tables_on_mobile_with_data_labels(): void
    {
        $teacherHtml = $this->actingAs($this->user('teacher'))->get('/teacher')->getContent();
        $adminHtml = $this->actingAs($this->user('admin'))->get('/admin')->getContent();

        // TASK-017 — phones get card-stacked rows (data-label pseudo
        // labels), never a horizontally squeezed table.
        $this->assertStringContainsString('ledger-table" data-stack', $teacherHtml);
        $this->assertStringContainsString('data-label="Status"', $teacherHtml);
        $this->assertStringContainsString('data-label="Tapped at"', $teacherHtml);
        $this->assertStringContainsString('ledger-table" data-stack', $adminHtml);

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('.ledger-table[data-stack] tbody td::before', $css);
    }

    #[Test]
    public function the_login_page_carries_smooth_sign_in_affordances(): void
    {
        $html = $this->get('/login')->getContent();

        // TASK-017 — demo chips that fill the form + password reveal.
        $this->assertStringContainsString('demo-chip', $html);
        $this->assertStringContainsString('data-email="admin@presence.test"', $html);
        $this->assertStringContainsString('data-email="teacher@presence.test"', $html);
        $this->assertStringContainsString('id="pw-toggle"', $html);
        $this->assertStringContainsString('class="pw-wrap"', $html);
        // the fill-the-form script is present and vanilla
        $this->assertStringContainsString("pass.value = 'password';", $html);
    }

    #[Test]
    public function the_design_tokens_match_the_owner_mockup_system_one_to_one(): void
    {
        // TASK-026 — ADR-036: the owner-supplied mockup bundle is the
        // design source of truth now (design system "Datum", light sage
        // M3 tonal palette); the marketplace value-match contract
        // (ADR-013/ADR-028) is retired for Core.
        $tokens = file_get_contents(public_path('css/tokens.css'));

        foreach ([
            '--surface', '--primary', '--tertiary-fixed', '--error',
            '--outline-variant', '--on-surface-variant',
        ] as $scale) {
            $this->assertStringContainsString($scale, $tokens, "tokens.css must carry the {$scale} Datum scale");
        }
        foreach ([
            '--bg:', '--text:', '--accent:', '--points:', '--radius:',
            '--font-display:', '--font-body:', '--font-label:', '--font-mono:',
        ] as $role) {
            $this->assertStringContainsString($role, $tokens, "tokens.css must define the semantic role {$role}");
        }
        // literal value spot-checks (the mockups' exact hexes)
        $this->assertMatchesRegularExpression('/--surface:\s*#f6fbed;/', $tokens);
        $this->assertMatchesRegularExpression('/--primary:\s*#0e0f0e;/', $tokens);
        $this->assertMatchesRegularExpression('/--tertiary-fixed:\s*#ffdf93;/', $tokens);
        $this->assertMatchesRegularExpression('/--error:\s*#ba1a1a;/', $tokens);
        // the mockup type scale ships verbatim
        $this->assertMatchesRegularExpression('/--fs-display:\s*56px;/', $tokens);
        $this->assertMatchesRegularExpression('/--fs-label-sm:\s*10px;/', $tokens);
    }

    #[Test]
    public function the_ground_is_light_sage_and_the_focus_floor_is_primary(): void
    {
        // TASK-026 — Datum base: sage surface ground, gold selection,
        // and the 2px primary focus floor (mockup geometry).
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/body\s*{[^}]*background:\s*var\(--bg\);/', $css);
        $this->assertMatchesRegularExpression('/::selection\s*{[^}]*var\(--tertiary-fixed\);/', $css);
        $this->assertMatchesRegularExpression('/:focus-visible\s*{[^}]*outline:\s*2px solid var\(--primary\);/', $css);
        $this->assertStringContainsString('.js-motion [data-reveal]', $css, 'reveal hidden state must be JS-gated');
        $this->assertStringContainsString('.js-motion [data-reveal].is-revealed', $css);
    }

    #[Test]
    public function the_layout_gates_motion_and_loads_the_reveal_module(): void
    {
        // TASK-019 — the marketplace's motion architecture: the inline
        // head script adds .js-motion ONLY when JS is on and motion is
        // allowed; the ESM module (with the vendored anime.js) drives
        // scroll reveals. Without JS everything stays visible.
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $html);
        $this->assertStringContainsString("classList.add('js-motion')", $html);
        $this->assertStringContainsString('js/motion.js', $html);
        $this->assertFileExists(public_path('js/vendor/anime.esm.min.js'));
        $this->assertStringContainsString(
            "import { animate, stagger, onScroll } from './vendor/anime.esm.min.js';",
            file_get_contents(public_path('js/motion.js')),
        );
        // reveals are progressive enhancement only
        $this->assertStringContainsString('data-reveal', $html);
    }

    #[Test]
    public function stamp_and_chip_tones_stay_inside_the_datum_palette(): void
    {
        // TASK-026 — present/late/absent and every event-chip tone map
        // onto the Datum roles (gold / dark-brown / error-container),
        // never onto colors outside the mockup's M3 tonal scales.
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.stamp-present\s*{[^}]*var\(--tertiary-fixed\)/', $css);
        $this->assertMatchesRegularExpression('/\.stamp-late\s*{[^}]*var\(--tertiary-container\)/', $css);
        $this->assertMatchesRegularExpression('/\.stamp-absent\s*{[^}]*var\(--error-container\)/', $css);
        // the chip tone mapping rides the semantic roles too
        $this->assertMatchesRegularExpression('/\.live-chip\[data-event-type\^="PAE_"\]\s*{[^}]*var\(--surface-variant\)/', $css);
        $this->assertMatchesRegularExpression('/\.live-chip\[data-event-type\^="RECYCLING_"\]\s*{[^}]*var\(--primary\)[^}]*var\(--tertiary-fixed\)/', $css);
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

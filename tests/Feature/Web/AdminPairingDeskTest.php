<?php

namespace Tests\Feature\Web;

use App\Models\Reader;
use App\Models\Student;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-011 — the dashboard pairing desk page (GET /admin/pairing): the
 * one-click arming surface that replaces the curl + admin-PAT dance for
 * pairing new students. Covers: guest redirect, teacher 403, the student
 * arming table, the server-rendered armed state, and the history list.
 */
class AdminPairingDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/admin/pairing')->assertRedirect('/login');
    }

    #[Test]
    public function teachers_are_forbidden(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();

        $this->actingAs($teacher)->get('/admin/pairing')->assertForbidden();
    }

    #[Test]
    public function an_admin_sees_the_student_table_with_an_arm_button_per_row(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/pairing');

        $response->assertOk()
            ->assertSee('Maria González')
            ->assertSee('Carlos Pérez')
            ->assertSeeText('Arm pairing')
            ->assertSeeText('No pairing session armed.')
            ->assertSeeText('Recently paired cards')
            // the arming endpoint the buttons call (TASK-010, unchanged)
            ->assertSee('/api/v1/admin/students/')
            ->assertSee('/api/v1/admin/pairing/status');
    }

    #[Test]
    public function an_armed_session_is_server_rendered_with_the_countdown(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $this->app->make(PairingService::class)->arm($student);

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSeeText('Armed for Maria González')
            ->assertSeeText('s left')
            ->assertSee('Now tap a FRESH card on the reader.')
            ->assertDontSeeText('No pairing session armed.');
    }

    #[Test]
    public function a_completed_pairing_appears_in_the_history_list(): void
    {
        $student = Student::create([
            'name' => 'Estudiante Escritorio',
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);
        $reader = Reader::where('type', 'classroom')->firstOrFail();

        $pairings = $this->app->make(PairingService::class);
        $pairings->arm($student);
        $pairings->pair($reader, 'DESKHISTORY1');

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSee('DESKHISTORY1')
            ->assertSeeText('Estudiante Escritorio')
            ->assertSee($reader->label)
            ->assertDontSeeText('No cards paired yet.');
    }

    #[Test]
    public function the_page_is_fully_translated_into_spanish(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();

        $this->actingAs($teacher)->get('/locale/es');

        // The nav link is admin-only; the page itself must be Spanish.
        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSeeText('Emparejar tarjetas')
            ->assertSeeText('Armar emparejamiento')
            ->assertSeeText('No hay sesión de emparejamiento armada.')
            ->assertSeeText('Tarjetas emparejadas recientemente');

        $this->actingAs($teacher)->get('/locale/en');
    }

    #[Test]
    public function a_rejected_tap_is_server_rendered_on_the_armed_window(): void
    {
        // TASK-014 — F5 mid-window keeps the operator informed: the armed
        // line rides with the rejection note (uid, reason, remediation).
        $target = Student::create([
            'name' => 'Estudiante Escritorio',
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);
        $burnedUid = $this->cardUidFor('Maria González');

        $pairings = $this->app->make(PairingService::class);
        $pairings->arm($target);
        $pairings->pair($this->reader('classroom'), $burnedUid);

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSeeText('Armed for Estudiante Escritorio')
            ->assertSeeText('s left')
            ->assertSeeText("Card {$burnedUid} was rejected")
            ->assertSeeText('that card is already paired')
            ->assertSeeText('./run unpair')
            ->assertSee('data-rejection-note');
    }

    #[Test]
    public function the_rejection_note_is_fully_translated_into_spanish(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $target = Student::create([
            'name' => 'Estudiante Escritorio ES',
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);
        $burnedUid = $this->cardUidFor('Maria González');

        $pairings = $this->app->make(PairingService::class);
        $pairings->arm($target);
        $pairings->pair($this->reader('classroom'), $burnedUid);

        $this->actingAs($teacher)->get('/locale/es');

        $this->actingAs($this->admin())
            ->get('/admin/pairing')
            ->assertOk()
            ->assertSeeText("La tarjeta {$burnedUid} fue rechazada")
            ->assertSeeText('esa tarjeta ya está emparejada')
            ->assertSeeText('./run unpair');

        $this->actingAs($teacher)->get('/locale/en');
    }

    #[Test]
    public function the_desk_script_stays_valid_javascript_after_a_completed_pairing(): void
    {
        // TASK-014 regression — the owner's bench bug: after the FIRST
        // completed pairing, lastCardUid became non-null and Blade's {{ }}
        // escaped json_encode's quotes into &quot;, killing the WHOLE
        // desk script (dead arm buttons + no polling on every reload).
        // JSON literals inside the script MUST render unescaped ({!! !!}).
        $student = Student::create([
            'name' => 'Estudiante Script',
            'grade' => '5°',
            'pae_enrolled' => false,
        ]);

        $pairings = $this->app->make(PairingService::class);
        $pairings->arm($student);
        $pairings->pair(Reader::where('type', 'classroom')->firstOrFail(), 'DESKSCRIPT1');

        $response = $this->actingAs($this->admin())->get('/admin/pairing');

        $response->assertOk()
            ->assertSee('var lastSeenUid = "DESKSCRIPT1";', false)
            ->assertDontSee('&quot;');

        // The script must also carry the TASK-014 templates as valid literals.
        $this->assertStringContainsString('var REJECTED_TPL = "Card ', $response->getContent());
        $this->assertStringContainsString('var ARMED_TPL = "Armed for ', $response->getContent());
    }

    #[Test]
    public function the_admin_nav_shows_the_pairing_desk_link(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSeeText('Pair cards')
            ->assertSee('/admin/pairing');
    }

    #[Test]
    public function the_armed_window_renders_as_a_draining_progress_bar(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $this->app->make(PairingService::class)->arm($student);

        $html = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();

        // TASK-017 — the window is visible at a glance: a sibling bar
        // (the script rewrites #pairing-state's textContent, so the bar
        // must NOT live inside it) driven by the configured window.
        $this->assertStringContainsString('id="pairing-countdown"', $html);
        $this->assertStringContainsString('class="countdown-fill"', $html);
        $this->assertStringContainsString('data-total="'.(int) config('presence.pairing_window_seconds').'"', $html);
        $this->assertStringContainsString('showCountdown(true)', $html);
        $this->assertStringContainsString('renderCountdown()', $html);
        // the bar is a SIBLING: it must not be nested inside the state box
        $statePos = strpos($html, 'id="pairing-state"');
        $stateClose = strpos($html, '</div>', $statePos);
        $barPos = strpos($html, 'id="pairing-countdown"');
        $this->assertGreaterThan($stateClose, $barPos, 'the countdown bar must survive the state box textContent rewrite');
    }

    #[Test]
    public function the_idle_desk_hides_the_progress_bar(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();

        // No armed session: the bar exists but is hidden from the start.
        $this->assertStringContainsString('id="pairing-countdown"', $html);
        $this->assertStringContainsString('countdown hidden', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    #[Test]
    public function the_desk_is_wired_to_the_realtime_pairing_channel(): void
    {
        // TASK-020 — the pairing page is a realtime page: same feed
        // client, same badge honesty, same token as the dashboards. The
        // boot node carries the mints; realtime.js connects; the desk
        // script applies `realtime:pairing` frames through the same
        // applyStatus() the poll uses.
        $html = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();

        // The feed client + the boot node (token + port, SSR-first). The
        // bootstrap JSON lives in an HTML-ATTRIBUTE context, so Blade's
        // escaped echo entity-encodes the quotes (the browser decodes
        // them back before dataset reads it — the documented convention).
        $this->assertStringContainsString('js/realtime.js', $html);
        $this->assertStringContainsString('id="pairing-realtime"', $html);
        $this->assertStringContainsString('data-realtime="', $html);
        $this->assertStringContainsString('&quot;port&quot;:'.(int) config('realtime.port'), $html);
        $this->assertMatchesRegularExpression('/&quot;token&quot;:&quot;1\.\d+\.[0-9a-f]{64}&quot;/', $html);

        // Badge honesty: the same live/connecting/offline grammar.
        $this->assertStringContainsString('id="live-badge"', $html);
        $this->assertStringContainsString('id="live-badge-text"', $html);
        $this->assertStringContainsString('data-state="connecting"', $html);

        // The desk script listens and applies frames through the ONE
        // state applier shared with the poll.
        $this->assertStringContainsString("addEventListener('realtime:pairing'", $html);
        $this->assertStringContainsString('applyStatus(e.detail', $html);
        $this->assertStringContainsString('function applyStatus(data)', $html);

        // The countdown can never lie beyond the configured window
        // (browser clock skew is clamped client-side too).
        $this->assertStringContainsString('Math.min(WINDOW_TOTAL', $html);
    }

    #[Test]
    public function the_status_box_follows_the_live_panel_full_bleed_grammar(): void
    {
        // TASK-021 — a live-panel is an UNPADDED container, so the status
        // box is a full-bleed tone strip (18px gutters, one rule line,
        // no inset card-in-card) and the draining bar meters the panel's
        // full width; the dashboards' answer boxes live in padded panels
        // and keep the inset base grammar. The idle note uses the
        // live-empty row grammar.
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.live-panel \.nl-answer\s*{[^}]*margin: 0[^}]*border-radius: 0/', $css);
        $this->assertMatchesRegularExpression('/\.live-panel \.nl-answer\s*{[^}]*padding: 13px 18px/', $css);
        $this->assertMatchesRegularExpression('/\.live-panel \.answer-ok\s*{[^}]*var\(--data-tint\)/', $css);
        $this->assertMatchesRegularExpression('/\.live-panel \.answer-error\s*{[^}]*var\(--accent-tint\)/', $css);
        $this->assertMatchesRegularExpression('/\.live-panel \.countdown\s*{[^}]*margin: 0[^}]*border-radius: 0/', $css);
        // the title shares the 18px live gutters (padding-0 panel) instead
        // of sitting flush at the edges, and the window sub carries the
        // mono uppercase label grammar instead of unstyled text
        $this->assertMatchesRegularExpression('/\.live-panel \.panel-label\s*{[^}]*margin: 0[^}]*padding: 14px 18px 13px/', $css);
        $this->assertMatchesRegularExpression('/\.live-panel-sub\s*{[^}]*text-transform: uppercase/', $css);
        // the idle note is a live-empty row, not a bare <p> at the edges
        $html = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();
        $this->assertStringContainsString('class="live-empty" id="pairing-idle"', $html);
    }

    #[Test]
    public function student_rows_expose_live_card_cells_for_backend_confirmed_updates(): void
    {
        // TASK-023 — the desk script updates the student row's card cell
        // from the backend-confirmed pairing payload (WS frame or poll),
        // so each row's card cell carries the marker the script targets.
        $html = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();

        $student = Student::firstOrFail();
        $this->assertStringContainsString('data-student-row="'.$student->id.'"', $html);
        $this->assertStringContainsString('data-card-cell="'.$student->id.'"', $html);
        $this->assertStringContainsString('function renderStudentCard(last)', $html);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }
}

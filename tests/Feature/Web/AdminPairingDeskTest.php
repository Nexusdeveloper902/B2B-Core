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

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }
}

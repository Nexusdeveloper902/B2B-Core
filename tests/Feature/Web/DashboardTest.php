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
            ->assertSeeText('GEMINI_API_KEY');
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

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

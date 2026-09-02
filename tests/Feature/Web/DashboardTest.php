<?php

namespace Tests\Feature\Web;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
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

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

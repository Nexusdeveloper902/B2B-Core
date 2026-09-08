<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 — the two new admin desks rendered by the redesign gap ledger:
 * /admin/students (roster + create + CSV import) and /admin/readers
 * (rename + switch mode). Both write through the admin API; this pins
 * the role walls and the server-rendered surface each script drives.
 */
class AdminDesksTest extends TestCase
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

    private function teacher(): User
    {
        return User::where('email', 'teacher@presence.test')->firstOrFail();
    }

    #[Test]
    public function guests_are_redirected_from_both_desks(): void
    {
        $this->get('/admin/students')->assertRedirect(route('login'));
        $this->get('/admin/readers')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_students_desk_renders_for_admins_only(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/students');

        $page->assertOk()
            ->assertSee(__('app.students_page'))
            ->assertSee(__('app.create_student'))
            ->assertSee(__('app.import_students'))
            ->assertSee(__('app.import_students_hint'))
            ->assertSee('Maria González')          // the roster truth
            // the write endpoints the desk's script calls
            ->assertSee('/api/v1/admin/students')
            ->assertSee('students/import');

        // The CSV desk is an admin surface: teachers keep their dashboard.
        $this->actingAs($this->teacher())->get('/admin/students')->assertForbidden();

        $studentUser = User::where('role', 'student')->firstOrFail();
        $this->actingAs($studentUser)->get('/admin/students')->assertForbidden();
    }

    #[Test]
    public function the_students_roster_shows_each_students_class_and_pae_state(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/students')->getContent();

        $this->assertStringContainsString('5° B', $html);          // class column
        $this->assertStringContainsString(__('app.student_pae'), $html);
        // Seeded truth: Maria is PAE-enrolled, Ana is not.
        $this->assertStringContainsString('Maria González', $html);
        $this->assertStringContainsString('Ana Martínez', $html);
    }

    #[Test]
    public function the_readers_desk_renders_for_admins_only(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/readers');

        $page->assertOk()
            ->assertSee(__('app.readers_page'))
            ->assertSee(__('app.reader_name'))
            ->assertSee('Demo Reader — Classroom/PAE')
            ->assertSee('Demo Reader — Recycling')
            // the write endpoint the desk's script calls
            ->assertSee('/api/v1/admin/readers/');

        $this->actingAs($this->teacher())->get('/admin/readers')->assertForbidden();

        $studentUser = User::where('role', 'student')->firstOrFail();
        $this->actingAs($studentUser)->get('/admin/readers')->assertForbidden();
    }
}

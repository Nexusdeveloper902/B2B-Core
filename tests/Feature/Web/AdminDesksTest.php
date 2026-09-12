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

    #[Test]
    public function destructive_actions_use_the_datum_modal_never_native_confirm(): void
    {
        // TASK-034 — no view may ship window.confirm anymore: rotate
        // (readers) and unpair (pairing) open the shared modal.
        $readers = $this->actingAs($this->admin())->get('/admin/readers')->getContent();
        $this->assertStringContainsString('id="reader-confirm"', $readers);
        $this->assertStringContainsString('data-confirm-modal', $readers);
        $this->assertStringContainsString('role="dialog"', $readers);
        $this->assertStringContainsString('window.DatumConfirm.open', $readers);
        $this->assertStringNotContainsString('window.confirm', $readers);
        // the cramped action cell is a flex row now (Blade + live rows)
        $this->assertStringContainsString('class="row-actions"', $readers);

        $pairing = $this->actingAs($this->admin())->get('/admin/pairing')->getContent();
        $this->assertStringContainsString('id="unpair-confirm"', $pairing);
        $this->assertStringContainsString('window.DatumConfirm.open', $pairing);
        $this->assertStringNotContainsString('window.confirm', $pairing);
    }

    #[Test]
    public function the_students_desk_couples_grade_to_class_and_searches_live(): void
    {
        // TASK-034 — grade 4 filters the class picker to 4° A/B with
        // grade tags on every option; the roster search is a live
        // fetch-swap (the GET form stays as the no-JS fallback).
        $html = $this->actingAs($this->admin())->get('/admin/students')->getContent();

        $this->assertStringContainsString('data-grade="5"', $html);
        $this->assertStringContainsString('data-grade="1"', $html);
        $this->assertStringContainsString('data-grade="11"', $html);
        $this->assertStringContainsString('syncClassesToGrade', $html);
        $this->assertStringContainsString('id="roster-search-form"', $html);
        $this->assertStringContainsString('id="roster-search"', $html);
        $this->assertStringContainsString('id="roster-pages"', $html);
        $this->assertStringContainsString('fetchRoster', $html);
    }

    #[Test]
    public function the_layout_nav_links_both_desks_for_admins_only(): void
    {
        // TASK-028 regression: the desks shipped reachable ONLY by URL
        // (owner: "I dont see the new GUI's"). The top nav — desktop and
        // the no-JS mobile menu — must carry both links for admins.
        $admin = $this->actingAs($this->admin())->get('/admin');

        $admin->assertOk()
            ->assertSee('/admin/students')
            ->assertSee('/admin/readers')
            ->assertSeeText(__('app.students_page'))
            ->assertSeeText(__('app.readers_page'));

        // Role wall: staff-without-admin and students never get the links
        // (the routes 403 for them — the nav must not advertise them).
        $teacher = $this->actingAs($this->teacher())->get('/teacher');
        $teacher->assertOk()
            ->assertDontSee('/admin/students')
            ->assertDontSee('/admin/readers');

        $studentUser = User::where('role', 'student')->firstOrFail();
        $student = $this->actingAs($studentUser)->get('/student');
        $student->assertOk()
            ->assertDontSee('/admin/students')
            ->assertDontSee('/admin/readers');
    }
}

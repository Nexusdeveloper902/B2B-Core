<?php

namespace Tests\Feature\Web;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-038 — the staff accounts desk (/admin/staff) renders for admins
 * only (creation form + staff list + the write endpoint the desk's
 * script calls). Teachers, kitchen, students and guests keep their own
 * surfaces.
 */
class StaffDeskTest extends TestCase
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
    public function guests_are_redirected_from_the_staff_desk(): void
    {
        $this->get('/admin/staff')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_staff_desk_renders_for_admins_only(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/staff');

        $page->assertOk()
            ->assertSee(__('app.staff_page'))
            ->assertSee(__('app.create_staff_account'))
            ->assertSee(__('app.staff_page_sub'))
            ->assertSee('teacher@presence.test')   // the staff truth
            ->assertSee('kitchen@presence.test')
            ->assertSee('5° B')                     // homeroom column
            // the write endpoint the desk's script calls
            ->assertSee('/api/v1/admin/staff');

        // The staff desk is an admin surface: every other role keeps
        // its own dashboard.
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->get('/admin/staff')->assertForbidden();

        $kitchen = User::where('email', 'kitchen@presence.test')->firstOrFail();
        $this->actingAs($kitchen)->get('/admin/staff')->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->get('/admin/staff')->assertForbidden();
    }

    #[Test]
    public function the_homeroom_picker_lists_only_classes_without_a_teacher(): void
    {
        // Seeded truth: 5° B is homed to teacher@presence.test, 5° A
        // is free — one class, one teacher, no full-grade dump.
        $homed = SchoolClass::where('name', '5° B')->firstOrFail();
        $free = SchoolClass::where('name', '5° A')->firstOrFail();

        $html = $this->actingAs($this->admin())->get('/admin/staff')->getContent();

        $this->assertStringNotContainsString('name="staff-classes" value="'.$homed->id.'"', $html);
        $this->assertStringContainsString('name="staff-classes" value="'.$free->id.'"', $html);
    }

    #[Test]
    public function the_email_field_auto_suggests_from_name_role_and_settings_domain(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/staff')->getContent();

        // The settings preset rides a data attribute the desk script
        // reads: {name}.{role}@{domain}, editable before submit.
        $this->assertStringContainsString('data-email-domain="presence.test"', $html);
        $this->assertStringContainsString(__('app.staff_email_hint'), $html);
    }

    #[Test]
    public function the_admin_nav_reaches_the_staff_desk(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin')->getContent();

        $this->assertStringContainsString(route('admin.staff'), $html);
    }

    #[Test]
    public function the_staff_desk_renders_in_spanish(): void
    {
        $page = $this->actingAs($this->admin())->withSession(['locale' => 'es'])->get('/admin/staff');

        $page->assertOk()
            ->assertSee('Cuentas de personal')
            ->assertSee('Crear acceso de personal');
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix C) — the stale-intended-URL wall (TASK-027): the
 * pre-login page survives ONLY when the fresh role can actually open
 * it, otherwise the role's own landing page wins — in both
 * directions, for every role.
 */
class IntendedRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function loginAs(string $email): TestResponse
    {
        return $this->post('/login', ['email' => $email, 'password' => 'password']);
    }

    #[Test]
    public function a_student_kept_out_of_the_teacher_page_lands_home(): void
    {
        $this->get('/teacher')->assertRedirect(route('login'));

        $this->loginAs('maria@presence.test')->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs(User::where('email', 'maria@presence.test')->firstOrFail());
    }

    #[Test]
    public function a_teacher_kept_out_of_admin_pages_lands_home(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));

        $this->loginAs('teacher@presence.test')->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function an_admin_kept_out_of_student_pages_lands_home(): void
    {
        $this->get('/student/history')->assertRedirect(route('login'));

        $this->loginAs('admin@presence.test')->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function an_in_scope_intended_page_survives_login(): void
    {
        $this->get('/admin/readers')->assertRedirect(route('login'));

        $this->loginAs('admin@presence.test')->assertRedirect(url('/admin/readers'));
    }

    #[Test]
    public function a_student_intended_page_survives_student_login(): void
    {
        $this->get('/student/history')->assertRedirect(route('login'));

        $this->loginAs('maria@presence.test')->assertRedirect(url('/student/history'));
    }

    #[Test]
    public function a_plain_login_lands_on_the_role_home(): void
    {
        $this->loginAs('maria@presence.test')->assertRedirect(route('student.dashboard'));
        $this->post('/logout');

        $this->loginAs('teacher@presence.test')->assertRedirect(route('dashboard'));
        $this->post('/logout');

        $this->loginAs('admin@presence.test')->assertRedirect(route('dashboard'));
    }
}

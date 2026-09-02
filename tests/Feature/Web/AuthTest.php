<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_landing_page_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_landing_page_redirects_authenticated_users_to_their_dashboard(): void
    {
        $this->actingAs($this->user('admin'))
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function guests_cannot_reach_dashboards(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/teacher')->assertRedirect(route('login'));
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    #[Test]
    public function login_succeeds_with_correct_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@presence.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->user('admin'));
    }

    #[Test]
    public function login_fails_with_wrong_credentials(): void
    {
        $response = $this->from(route('login'))->post('/login', [
            'email' => 'admin@presence.test',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function teachers_cannot_open_the_admin_dashboard(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get('/admin')
            ->assertForbidden();
    }

    #[Test]
    public function admins_can_open_both_dashboards(): void
    {
        $this->actingAs($this->user('admin'))
            ->get('/admin')->assertOk();

        $this->actingAs($this->user('admin'))
            ->get('/teacher')->assertOk();
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $user = $this->user('teacher');
        $this->actingAs($user);

        $this->post('/logout');
        $this->assertGuest();
    }

    #[Test]
    public function a_logged_in_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get('/login')
            ->assertRedirect(route('dashboard'));
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

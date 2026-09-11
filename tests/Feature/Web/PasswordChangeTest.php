<?php

namespace Tests\Feature\Web;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030-A (ADR-044) — the rotation wall. Flagged accounts (fresh
 * provisioned logins) can open only the password-change form, logout
 * and the locale switcher; every other authenticated page bounces them
 * to the form (HTML) or answers a bilingual 403 (JSON). Rotating clears
 * the flag and lands on the role's own dashboard; the form stays
 * available voluntarily afterwards.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function flaggedStudent(): User
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $user = $student->account;
        $user->update(['must_change_password' => true]);

        return $user->fresh();
    }

    #[Test]
    public function a_flagged_student_bounces_off_every_page_to_the_form(): void
    {
        $user = $this->flaggedStudent();

        // The role wall would 403 here for a student — the rotation wall
        // runs FIRST and redirects instead (ordering pinned).
        $this->actingAs($user)->get('/student')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/student/history')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/realtime/token')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function a_flagged_admin_bounces_too(): void
    {
        $admin = User::where('email', 'admin@presence.test')->firstOrFail();
        $admin->update(['must_change_password' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('password.change'));
        $this->actingAs($admin)->get('/admin/students')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function json_callers_get_a_bilingual_403_instead_of_a_redirect(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->getJson('/api/v1/recycling/leaderboard')
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('api.password_change_required')]);
    }

    #[Test]
    public function logout_and_locale_stay_reachable_while_flagged(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->get('/locale/es')
            ->assertRedirect('/');

        $this->actingAs($this->flaggedStudent())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function unknown_paths_keep_their_404_while_flagged(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->get('/no-such-page-anywhere')
            ->assertNotFound();
    }

    #[Test]
    public function the_form_renders_in_both_languages(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->get(route('password.change'))
            ->assertOk()
            ->assertSee('Set a new password', false);

        $this->withSession(['locale' => 'es'])
            ->actingAs($this->flaggedStudent())
            ->get(route('password.change'))
            ->assertOk()
            ->assertSee('Define una nueva contraseña', false);
    }

    #[Test]
    public function rotation_needs_the_current_password_and_a_confirmed_replacement(): void
    {
        $user = $this->flaggedStudent();

        // Wrong current password.
        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'not-the-password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertSessionHasErrors('current_password');

        // Too short.
        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        // Unconfirmed.
        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function rotating_to_the_current_or_initial_password_is_refused(): void
    {
        // Finding 4.1 (BLOCKER): re-submitting the shared initial value
        // as the "new" password used to clear the flag and leave the
        // account on the well-known secret.
        $user = $this->flaggedStudent();

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);

        // Same refusal when the current password is already personal but
        // the replacement is the documented initial value.
        $user->update(['password' => 'a-personal-secret-1', 'must_change_password' => true]);

        $this->actingAs($user->fresh())
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'a-personal-secret-1',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    #[Test]
    public function a_flagged_pat_caller_gets_the_403_too(): void
    {
        // Finding 2.1: the wall resolves sanctum on demand — Bearer-PAT
        // traffic from a flagged account is gated, not just sessions.
        $admin = User::where('email', 'admin@presence.test')->firstOrFail();
        $token = $admin->createToken('audit')->plainTextToken;

        $this->getJson('/api/v1/recycling/leaderboard', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $admin->update(['must_change_password' => true]);

        $this->getJson('/api/v1/recycling/leaderboard', [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('api.password_change_required')]);
    }

    #[Test]
    public function a_successful_rotation_clears_the_flag_and_lands_home(): void
    {
        $user = $this->flaggedStudent();

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertRedirect(route('student.dashboard'));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-secret', $fresh->password));

        // The wall is gone: the dashboard renders now.
        $this->actingAs($fresh)->get('/student')->assertOk();
    }

    #[Test]
    public function the_old_password_dies_with_the_rotation(): void
    {
        $user = $this->flaggedStudent();

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertRedirect(route('student.dashboard'));

        $this->post('/logout');

        // Fresh guest flow (no actingAs before these — stickiness).
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-secret'])
            ->assertRedirect(route('student.dashboard'));
    }

    #[Test]
    public function an_admin_rotates_voluntarily_and_lands_on_the_staff_dashboard(): void
    {
        $admin = User::where('email', 'admin@presence.test')->firstOrFail();

        // Unflagged: every page opens, the form included.
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get(route('password.change'))->assertOk();

        $this->actingAs($admin)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'an-admin-secret-1',
                'password_confirmation' => 'an-admin-secret-1',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($admin->fresh()->must_change_password);
    }

    #[Test]
    public function guests_never_reach_the_form(): void
    {
        $this->get(route('password.change'))->assertRedirect(route('login'));
    }
}

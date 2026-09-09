<?php

namespace Tests\Feature\Web;

use App\Models\PointsLedger;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-025 item 5 — student self-service accounts (spec §11/§12/§30):
 * login, own-points views, and the authorization floor (a student sees
 * ONLY their own data; staff dashboards are closed to them).
 */
class StudentAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function a_student_logs_in_and_lands_on_their_own_dashboard(): void
    {
        $response = $this->post('/login', [
            'email' => 'maria@presence.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('student.dashboard'));

        $this->get('/student')
            ->assertOk()
            ->assertSee(__('app.student_points_balance'))
            ->assertSee('Maria González');
    }

    #[Test]
    public function the_student_dashboard_shows_only_their_own_movements(): void
    {
        $maria = $this->studentUser('maria');
        $this->award($this->studentIdOf('Maria González'), 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 25);

        // The dashboard shows Maria's balance (10, not Carlos' 25) and
        // only HER movement in the recent-activity panel ('+10' chip —
        // Carlos' '+25' must never appear in HER activity list). The
        // shared leaderboard legitimately shows other students (spec
        // §22 — bare numbers, no +/- chips), so the isolation proof
        // rides on the signed activity chips.
        $page = $this->actingAs($maria)->get('/student');

        $page->assertOk()
            ->assertSeeText('+10')
            ->assertDontSeeText('+25');

        $history = $this->actingAs($maria)->get('/student/history');

        $history->assertOk()
            ->assertSee(__('app.student_history_title'))
            ->assertDontSee('Carlos');
    }

    #[Test]
    public function the_rewards_catalog_and_own_redemptions_render(): void
    {
        $maria = $this->studentUser('maria');
        $this->award($this->studentIdOf('Maria González'), 100);

        $page = $this->actingAs($maria)->get('/student/rewards');

        $page->assertOk()
            ->assertSee(__('app.student_rewards_title'))
            ->assertSee('Early lunch pass')
            ->assertSee(__('app.student_reward_stock_unlimited'));

        // Own redemption appears after a desk spend; another student's
        // never does.
        $reward = Reward::where('name', 'Leaderboard shout-out')->firstOrFail();
        $this->actingAs($this->adminUser())->postJson("/api/v1/students/{$this->studentIdOf('Maria González')}/redeem", [
            'reward_id' => $reward->id,
        ])->assertOk();

        $this->actingAs($maria)->get('/student/rewards')
            ->assertOk()
            ->assertSee(__('app.student_redemptions'))
            ->assertSee($reward->name);

        $this->assertSame(1, RewardRedemption::where('student_id', $this->studentIdOf('Maria González'))->count());
    }

    #[Test]
    public function students_are_locked_out_of_staff_dashboards(): void
    {
        $maria = $this->studentUser('maria');

        $this->actingAs($maria)->get('/dashboard')->assertForbidden();
        $this->actingAs($maria)->get('/admin')->assertForbidden();
        $this->actingAs($maria)->get('/admin/pairing')->assertForbidden();
    }

    #[Test]
    public function staff_stay_on_the_staff_dashboards_after_login(): void
    {
        $this->post('/login', [
            'email' => 'admin@presence.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $this->get('/student')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_logged_in_student_opening_a_guest_page_lands_on_their_own_dashboard(): void
    {
        // UI pass 2026-09-09 — the framework default sent every authenticated
        // user who opened /login to the staff /dashboard, so students hit a
        // bare 403 dead end. The redirect is now role-aware.
        $maria = $this->studentUser('maria');

        $this->actingAs($maria)->get('/login')->assertRedirect(route('student.dashboard'));
        $this->actingAs($this->adminUser())->get('/login')->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function access_denied_renders_inside_the_app_shell_with_a_way_out(): void
    {
        // UI pass 2026-09-09 — 403 used to render Laravel's bare abort page:
        // no topbar, no logout, no escape hatch.
        $maria = $this->studentUser('maria');

        $this->actingAs($maria)->get('/admin')
            ->assertForbidden()
            ->assertSee(__('app.error_403_title'))
            ->assertSee(__('app.error_back'))
            ->assertSee(route('student.dashboard'), false);
    }

    #[Test]
    public function the_mobile_menu_keeps_the_logout_form_reachable(): void
    {
        // UI pass 2026-09-09 — the ≤620px rule that hides the topbar logout
        // button must target ONLY the topbar's direct form; a descendant
        // selector also hid the hamburger menu's logout form, leaving
        // phones with no way to sign out.
        $css = file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('.topbar-tools > .inline-form { display: none; }', $css);
        $this->assertStringNotContainsString('.topbar-tools .inline-form { display: none; }', $css);
    }

    // ------------------------------------------------------------- helpers

    private function studentUser(string $slug)
    {
        return User::where('email', "{$slug}@presence.test")->firstOrFail();
    }

    private function adminUser()
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function studentIdOf(string $name): int
    {
        return Student::where('name', $name)->firstOrFail()->id;
    }

    private function award(int $studentId, int $points): void
    {
        PointsLedger::create([
            'student_id' => $studentId,
            'delta' => $points,
            'reason' => 'recycling_deposit',
        ]);
    }
}

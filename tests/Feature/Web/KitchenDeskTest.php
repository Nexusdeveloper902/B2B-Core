<?php

namespace Tests\Feature\Web;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-037 — the kitchen role (ADR-054): kitchen users authenticate
 * through the normal login flow, land on /kitchen, are RESTRICTED to
 * the kitchen workflow (every other desk 403s), and the page renders
 * the glanceable meal-service state. Admins may open the desk too
 * (verification); teachers and students may not.
 */
class KitchenDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function kitchen(): User
    {
        return User::where('email', 'kitchen@presence.test')->firstOrFail();
    }

    private function user(string $role): User
    {
        return match ($role) {
            'admin' => User::where('email', 'admin@presence.test')->firstOrFail(),
            'teacher' => User::where('email', 'teacher@presence.test')->firstOrFail(),
            default => User::where('role', $role)->firstOrFail(),
        };
    }

    #[Test]
    public function the_seeded_kitchen_user_exists_with_the_kitchen_role(): void
    {
        $kitchen = $this->kitchen();

        $this->assertSame(UserRole::Kitchen->value, $kitchen->role);
        $this->assertTrue($kitchen->isKitchen());
    }

    #[Test]
    public function the_kitchen_page_renders_for_kitchen_users(): void
    {
        $html = $this->actingAs($this->kitchen())->get('/kitchen')->assertOk()->getContent();

        $this->assertStringContainsString('kitchen-state', $html);
        $this->assertStringContainsString('data-realtime', $html);
        $this->assertStringContainsString(__('app.kitchen_waiting'), $html);
        // The glanceable panel + the recent list are the whole workflow.
        $this->assertStringContainsString('kitchen-recent', $html);
    }

    #[Test]
    public function admins_may_open_the_kitchen_desk(): void
    {
        $this->actingAs($this->user('admin'))->get('/kitchen')->assertOk();
    }

    #[Test]
    public function kitchen_users_are_walled_off_every_other_desk(): void
    {
        $kitchen = $this->kitchen();
        $student = Student::firstOrFail();

        $this->actingAs($kitchen)->get('/admin')->assertForbidden();
        $this->actingAs($kitchen)->get('/dashboard')->assertForbidden();
        $this->actingAs($kitchen)->get('/teacher')->assertForbidden();
        $this->actingAs($kitchen)->get('/admin/students')->assertForbidden();
        $this->actingAs($kitchen)->get('/admin/settings')->assertForbidden();
        $this->actingAs($kitchen)->get('/admin/reports/pae')->assertForbidden();
        $this->actingAs($kitchen)->get('/student')->assertForbidden();
        $this->actingAs($kitchen)->get("/parent/students/{$student->id}")->assertForbidden();
    }

    #[Test]
    public function teachers_and_students_cannot_open_the_kitchen_desk(): void
    {
        $this->actingAs($this->user('teacher'))->get('/kitchen')->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->get('/kitchen')->assertForbidden();
    }

    #[Test]
    public function kitchen_login_lands_on_the_kitchen_desk(): void
    {
        $response = $this->post('/login', [
            'email' => 'kitchen@presence.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('kitchen'));
        $this->assertAuthenticatedAs($this->kitchen());
    }

    #[Test]
    public function an_intended_url_never_leaks_a_kitchen_user_into_staff_desks(): void
    {
        // A guest hitting /admin stored the intended URL; the kitchen
        // login must still land on /kitchen (the TASK-027 rule, extended).
        $this->get('/admin');

        $response = $this->post('/login', [
            'email' => 'kitchen@presence.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('kitchen'));
    }

    #[Test]
    public function the_kitchen_nav_shows_only_the_kitchen_desk(): void
    {
        $html = $this->actingAs($this->kitchen())->get('/kitchen')->assertOk()->getContent();

        $this->assertStringContainsString(__('app.kitchen_desk'), $html);
        $this->assertStringNotContainsString(__('app.admin_dashboard'), $html);
        $this->assertStringNotContainsString(__('app.students_page'), $html);
    }

    #[Test]
    public function the_kitchen_page_translates_to_spanish(): void
    {
        $this->actingAs($this->kitchen())->get('/locale/es');
        $html = $this->actingAs($this->kitchen())->get('/kitchen')->assertOk()->getContent();

        $this->assertStringContainsString(__('app.kitchen_title'), $html);
        $this->assertStringContainsString('Esperando el próximo toque', $html);
    }

    #[Test]
    public function the_kitchen_page_renders_meal_scenario_rows_from_the_seeder(): void
    {
        // The demo seeder's past-day scenario events ride the SSR feed:
        // the recent list shows served meals AND flagged attempts with
        // their reasons (the operator sees the whole story at a glance).
        $html = $this->actingAs($this->kitchen())->get('/kitchen')->assertOk()->getContent();

        $this->assertStringContainsString('Maria González', $html);
        $this->assertStringContainsString(__('api.pae_reason_duplicate'), $html);
    }
}

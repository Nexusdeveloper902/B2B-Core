<?php

namespace Tests\Feature\Api;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-038 (ADR-058) — the admin GUI mints staff logins. Teacher,
 * kitchen and admin accounts are created through POST
 * /api/v1/admin/staff with an admin-chosen temporary password
 * (display-once) + forced first-login rotation; teachers optionally
 * take homeroom classes in the same transaction. Duplicates are an
 * honest 422 (never a silent skip, never a 500 on a lost race);
 * student-role minting and class assignment to non-teachers are
 * refused; non-admins are walled out.
 */
class StaffManagementTest extends TestCase
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
    public function admin_creates_a_teacher_with_homeroom_classes(): void
    {
        $class = SchoolClass::where('name', '5° A')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Prof. Luis Gómez',
                'email' => 'luis.g@presence.test',
                'role' => 'teacher',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
                'class_ids' => [$class->id],
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'staff' => [
                    'name' => 'Prof. Luis Gómez',
                    'email' => 'luis.g@presence.test',
                    'role' => 'teacher',
                    'classes' => ['5° A'],
                ],
                'account' => [
                    'email' => 'luis.g@presence.test',
                    'temporary_password' => 'cambia-ya-01',
                    'must_change_password' => true,
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'luis.g@presence.test',
            'role' => 'teacher',
            'student_id' => null,
            'must_change_password' => true,
        ]);
        $this->assertTrue(Hash::check('cambia-ya-01', User::where('email', 'luis.g@presence.test')->firstOrFail()->password));
        $this->assertSame(
            User::where('email', 'luis.g@presence.test')->firstOrFail()->id,
            $class->refresh()->teacher_user_id
        );
    }

    #[Test]
    public function admin_creates_kitchen_and_admin_logins_without_classes(): void
    {
        foreach (['kitchen', 'admin'] as $role) {
            $response = $this->actingAs($this->admin())
                ->postJson('/api/v1/admin/staff', [
                    'name' => "Staffer {$role}",
                    'email' => "{$role}.nuevo@presence.test",
                    'role' => $role,
                    'password' => 'cambia-ya-01',
                    'password_confirmation' => 'cambia-ya-01',
                ]);

            $response->assertOk()->assertJsonPath('staff.role', $role);

            $this->assertDatabaseHas('users', [
                'email' => "{$role}.nuevo@presence.test",
                'role' => $role,
                'must_change_password' => true,
            ]);
        }
    }

    #[Test]
    public function the_minting_response_carries_no_secret_material_besides_the_display_once_pair(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Limpia Registro',
                'email' => 'limpia@presence.test',
                'role' => 'kitchen',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
            ]);

        $response->assertOk();

        $account = $response->json('account');
        $this->assertSame(['email', 'temporary_password', 'must_change_password'], array_keys($account));
        $this->assertArrayNotHasKey('temporary_password', $response->json('staff'));
    }

    #[Test]
    public function duplicate_emails_are_rejected_case_insensitively(): void
    {
        $payload = [
            'name' => 'Duplicado',
            'email' => 'teacher@presence.test', // seeded (lowercase)
            'role' => 'kitchen',
            'password' => 'cambia-ya-01',
            'password_confirmation' => 'cambia-ya-01',
        ];

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', $payload)
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'duplicate']);

        // Case variant hits the same invariant (MariaDB collations are
        // case-insensitive while SQLite's are not — the desk owns the
        // truth on both engines).
        $payload['email'] = 'TEACHER@presence.test';

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', $payload)
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'duplicate']);
    }

    #[Test]
    public function student_role_minting_is_refused(): void
    {
        // Student logins are the 1:1 layer minted by the students desk
        // (ADR-033/ADR-044) — this desk never mints a bare one.
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Falso Estudiante',
                'email' => 'falso@presence.test',
                'role' => 'student',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'falso@presence.test']);
    }

    #[Test]
    public function class_assignment_on_a_non_teacher_role_is_refused(): void
    {
        $class = SchoolClass::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Ayudante Cocina',
                'email' => 'ayudante@presence.test',
                'role' => 'kitchen',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
                'class_ids' => [$class->id],
            ])
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'classes_teacher_only']);

        $this->assertDatabaseMissing('users', ['email' => 'ayudante@presence.test']);
    }

    #[Test]
    public function unknown_classes_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Prof. Fantasma',
                'email' => 'fantasma@presence.test',
                'role' => 'teacher',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
                'class_ids' => [999999],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'fantasma@presence.test']);
    }

    #[Test]
    public function non_admins_cannot_mint_staff(): void
    {
        $payload = [
            'name' => 'Intruso',
            'email' => 'intruso@presence.test',
            'role' => 'kitchen',
            'password' => 'cambia-ya-01',
            'password_confirmation' => 'cambia-ya-01',
        ];

        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->postJson('/api/v1/admin/staff', $payload)->assertForbidden();

        $kitchen = User::where('email', 'kitchen@presence.test')->firstOrFail();
        $this->actingAs($kitchen)->postJson('/api/v1/admin/staff', $payload)->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->postJson('/api/v1/admin/staff', $payload)->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruso@presence.test']);
    }

    #[Test]
    public function a_fresh_staff_login_is_forced_through_password_rotation(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/staff', [
                'name' => 'Prof. Nueva',
                'email' => 'nueva.profe@presence.test',
                'role' => 'teacher',
                'password' => 'cambia-ya-01',
                'password_confirmation' => 'cambia-ya-01',
            ])
            ->assertOk();

        $fresh = User::where('email', 'nueva.profe@presence.test')->firstOrFail();

        // The temporary password IS the credential (same assertion the
        // student-provisioning tests pin)…
        $this->assertTrue(Hash::check('cambia-ya-01', $fresh->password));

        // …but every desk bounces the flagged account to the rotation
        // form first (the PasswordChangeTest wall idiom).
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('password.change'));
        $this->actingAs($fresh)->get('/admin/staff')->assertRedirect(route('password.change'));
    }
}

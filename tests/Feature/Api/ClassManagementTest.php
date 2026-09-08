<?php

namespace Tests\Feature\Api;

use App\Models\RosterUpdate;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-029 — class creation through the GUI (the students desk's
 * missing first step). POST /api/v1/admin/classes is admin-only,
 * duplicate names are a bilingual 422 (case-insensitive, mirroring
 * the student desk's rule), teacher assignment is optional and
 * teacher-role-checked, and every committed create rides the roster
 * channel (one roster_updates row in the same transaction).
 */
class ClassManagementTest extends TestCase
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
    public function an_admin_creates_a_class_and_a_roster_frame_rides_the_transaction(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/classes', [
                'name' => '6° A',
                'teacher_user_id' => $this->teacher()->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('class.name', '6° A')
            ->assertJsonPath('class.teacher_name', 'Prof. Elena Ramírez');

        $this->assertDatabaseHas('classes', ['name' => '6° A']);

        // The roster channel frame — committed with the class.
        $frame = RosterUpdate::where('type', 'class_created')->latest('id')->first();
        $this->assertNotNull($frame, 'no class_created roster frame was written');
        $this->assertSame('6° A', $frame->payload['name']);
        $this->assertSame('Prof. Elena Ramírez', $frame->payload['teacher_name']);
    }

    #[Test]
    public function a_class_without_a_teacher_is_valid(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/classes', ['name' => '7° C']);

        $response->assertOk()
            ->assertJsonPath('class.teacher_name', null)
            ->assertJsonPath('message', __('api.class_created', ['name' => '7° C']));
    }

    #[Test]
    public function duplicate_names_are_a_bilingual_422_case_insensitive(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/classes', ['name' => '5° B'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('reason', 'duplicate')
            ->assertJsonPath('message', __('api.class_duplicate', ['name' => '5° B']));

        // Case-insensitive: "5° b" is still 5° B.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/classes', ['name' => '5° b'])
            ->assertStatus(422);

        $this->assertSame(1, SchoolClass::where('name', '5° B')->count(), 'no second 5° B may exist');
    }

    #[Test]
    public function only_teacher_users_may_be_assigned(): void
    {
        $student = User::where('role', 'student')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/classes', [
                'name' => '8° A',
                'teacher_user_id' => $student->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('teacher_user_id');

        $this->assertDatabaseMissing('classes', ['name' => '8° A']);
    }

    #[Test]
    public function the_classes_desk_is_an_admin_surface(): void
    {
        $this->actingAs($this->teacher(), 'sanctum')
            ->postJson('/api/v1/admin/classes', ['name' => '9° A'])
            ->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/admin/classes', ['name' => '9° A'])
            ->assertForbidden();

        // No GET route exists (POST only): a guest probing the path gets
        // 405 method-not-allowed — no class surface for guests either way.
        $this->getJson('/api/v1/admin/classes')->assertStatus(405);
    }
}

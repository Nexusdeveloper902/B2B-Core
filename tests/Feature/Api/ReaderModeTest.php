<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReaderModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function an_admin_can_change_a_readers_mode(): void
    {
        $reader = $this->reader('classroom');

        $response = $this->actingAs($this->seededUser('admin'))
            ->postJson("/api/v1/admin/readers/{$reader->id}/mode", [
                'active_event_type' => 'PAE_LUNCH',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reader.active_event_type', 'PAE_LUNCH');

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'active_event_type' => 'PAE_LUNCH',
        ]);
    }

    #[Test]
    public function the_put_alias_works_too(): void
    {
        $reader = $this->reader('classroom');

        $this->actingAs($this->seededUser('admin'))
            ->putJson("/api/v1/admin/readers/{$reader->id}/mode", [
                'active_event_type' => 'PAE_BREAKFAST',
            ])->assertOk();
    }

    #[Test]
    public function a_teacher_cannot_change_a_readers_mode(): void
    {
        $reader = $this->reader('classroom');

        $this->actingAs($this->seededUser('teacher'))
            ->postJson("/api/v1/admin/readers/{$reader->id}/mode", [
                'active_event_type' => 'PAE_LUNCH',
            ])->assertForbidden();

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'active_event_type' => 'CLASS_ATTENDANCE',
        ]);
    }

    #[Test]
    public function a_guest_cannot_change_a_readers_mode(): void
    {
        $reader = $this->reader('classroom');

        $this->postJson("/api/v1/admin/readers/{$reader->id}/mode", [
            'active_event_type' => 'PAE_LUNCH',
        ])->assertUnauthorized();
    }

    #[Test]
    public function unknown_event_types_are_rejected(): void
    {
        $reader = $this->reader('classroom');

        $this->actingAs($this->seededUser('admin'))
            ->postJson("/api/v1/admin/readers/{$reader->id}/mode", [
                'active_event_type' => 'HOMEWORK_SUBMISSION',
            ])->assertUnprocessable();
    }

    private User $admin;

    private function seededUser(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }
}

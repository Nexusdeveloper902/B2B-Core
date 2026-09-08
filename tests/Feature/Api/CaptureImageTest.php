<?php

namespace Tests\Feature\Api;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 (gap E1) — authorized streaming of a stored capture image
 * (GET /api/v1/admin/captures/{deposit}/image). The images stay on the
 * PRIVATE disk; this route is the single admin-authed door. Covers the
 * happy stream, the 404 when the file is missing, and the role wall on
 * both other staff roles.
 */
class CaptureImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@presence.test')->firstOrFail();
    }

    /**
     * A classified deposit whose image is (fake-)stored on the local disk.
     */
    private function depositWithImage(string $content = 'jpeg-bytes'): RecyclingDeposit
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        PresenceEvent::create([
            'card_id' => $student->cards()->firstOrFail()->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);

        $path = 'captures/'.now()->format('Ymd-His').'-test.jpg';
        Storage::disk('local')->put($path, $content);

        return RecyclingDeposit::create([
            'event_id' => PresenceEvent::latest('id')->firstOrFail()->id,
            'image_path' => $path,
            'material_class' => 'plastic',
            'confidence' => 0.99,
            'points_awarded' => 10,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);
    }

    #[Test]
    public function an_admin_streams_the_capture_image(): void
    {
        $deposit = $this->depositWithImage('the-real-bytes');

        $response = $this->actingAs($this->admin())
            ->get("/api/v1/admin/captures/{$deposit->id}/image");

        $response->assertOk();
        $this->assertSame('the-real-bytes', $response->streamedContent());
        // Private audit artifact: never re-cached by shared caches.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_deposit_without_a_stored_image_is_a_404(): void
    {
        $deposit = $this->depositWithImage();
        Storage::disk('local')->delete($deposit->image_path);

        $this->actingAs($this->admin())
            ->get("/api/v1/admin/captures/{$deposit->id}/image")
            ->assertNotFound();
    }

    #[Test]
    public function the_image_door_rejects_guests(): void
    {
        // Before any actingAs (session stickiness). API plane: JSON 401.
        $deposit = $this->depositWithImage();

        $this->getJson("/api/v1/admin/captures/{$deposit->id}/image")
            ->assertUnauthorized();
    }

    #[Test]
    public function the_image_door_is_closed_to_teachers_and_students(): void
    {
        $deposit = $this->depositWithImage();

        $this->actingAs($this->teacher())
            ->get("/api/v1/admin/captures/{$deposit->id}/image")
            ->assertForbidden();

        $studentUser = User::where('role', 'student')->firstOrFail();
        $this->actingAs($studentUser)
            ->get("/api/v1/admin/captures/{$deposit->id}/image")
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_deposit_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->get('/api/v1/admin/captures/999999/image')
            ->assertNotFound();
    }
}

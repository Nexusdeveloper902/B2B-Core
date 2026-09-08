<?php

namespace Tests\Feature\Api;

use App\Models\Reader;
use App\Models\RosterUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 — the reader management settings surface (PUT
 * /api/v1/admin/readers/{reader}): rename + switch the active mode in ONE
 * request, the backing call of the /admin/readers desk (the mode-only
 * endpoint keeps its own pinned contract in ReaderModeTest).
 */
class ReaderSettingsTest extends TestCase
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
    public function an_admin_renames_a_reader_and_switches_its_mode(): void
    {
        $reader = Reader::where('label', 'Demo Reader — Classroom/PAE')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->putJson("/api/v1/admin/readers/{$reader->id}", [
                'label' => 'Aula 12 — Entrada',
                'active_event_type' => 'ENTRY',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'reader' => [
                    'id' => $reader->id,
                    'label' => 'Aula 12 — Entrada',
                    'active_event_type' => 'ENTRY',
                ],
            ]);

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'label' => 'Aula 12 — Entrada',
            'active_event_type' => 'ENTRY',
        ]);
    }

    #[Test]
    public function an_invalid_mode_is_rejected(): void
    {
        $reader = Reader::firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/v1/admin/readers/{$reader->id}", [
                'label' => 'Aula 12',
                'active_event_type' => 'NOT_A_MODE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['active_event_type']);

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'label' => $reader->label,
        ]);
    }

    #[Test]
    public function a_short_label_is_rejected(): void
    {
        $reader = Reader::firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/v1/admin/readers/{$reader->id}", [
                'label' => 'AB',
                'active_event_type' => 'ENTRY',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    #[Test]
    public function the_settings_surface_rejects_guests(): void
    {
        // Before any actingAs (session stickiness).
        $reader = Reader::firstOrFail();

        $this->putJson("/api/v1/admin/readers/{$reader->id}", [
            'label' => 'Intento Anónimo',
            'active_event_type' => 'ENTRY',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'label' => $reader->label,
        ]);
    }

    #[Test]
    public function the_settings_surface_rejects_teachers(): void
    {
        $reader = Reader::firstOrFail();

        $this->actingAs($this->teacher())
            ->putJson("/api/v1/admin/readers/{$reader->id}", [
                'label' => 'Intento Docente',
                'active_event_type' => 'ENTRY',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('readers', [
            'id' => $reader->id,
            'label' => $reader->label,
        ]);
    }

    #[Test]
    public function an_unknown_reader_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/readers/999999', [
                'label' => 'Fantasma',
                'active_event_type' => 'ENTRY',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function every_reader_change_writes_a_roster_frame_in_the_same_transaction(): void
    {
        // TASK-029 — BOTH write surfaces (the settings endpoint AND the
        // mode-only endpoint) log one reader_updated frame: whichever
        // surface changed the reader, every surface showing it goes live.
        $reader = Reader::firstOrFail();

        $this->actingAs($this->admin())
            ->putJson("/api/v1/admin/readers/{$reader->id}", [
                'label' => 'Live Reader — Puerta',
                'active_event_type' => 'ENTRY',
            ])->assertOk();

        $frame = RosterUpdate::where('type', 'reader_updated')->latest('id')->first();
        $this->assertNotNull($frame, 'no reader_updated roster frame was written');
        $this->assertSame($reader->id, $frame->payload['id']);
        $this->assertSame('Live Reader — Puerta', $frame->payload['label']);
        $this->assertSame('ENTRY', $frame->payload['active_event_type']);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/readers/{$reader->id}/mode", [
                'active_event_type' => 'EXIT',
            ])->assertOk();

        $modeFrame = RosterUpdate::where('type', 'reader_updated')->latest('id')->first();
        $this->assertNotSame($frame->id, $modeFrame->id, 'the mode-only endpoint logs its own frame');
        $this->assertSame('EXIT', $modeFrame->payload['active_event_type']);
    }
}

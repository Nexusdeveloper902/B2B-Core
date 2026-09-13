<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RosterUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reader deletion (DELETE /api/v1/admin/readers/{reader}).
 *
 * The reader row goes; tap events + deposits + pending captures go
 * with it (explicit child-first deletes — deterministic where the
 * sqlite pragma is off, cf. CardUnpairController). Pairing history
 * survives with the link cleared; the old Bearer dies with the row
 * and a reader_deleted roster frame drops the desk row live.
 */
class ReaderDeletionTest extends TestCase
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
    public function an_admin_deletes_a_reader_cascading_events_and_clearing_history_links(): void
    {
        Storage::fake('local');
        $reader = Reader::orderBy('id')->firstOrFail();
        $card = Card::firstOrFail();

        $event = PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => $reader->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->subDay(),
        ]);
        Storage::disk('local')->put('deposit-img.jpg', 'bytes');
        RecyclingDeposit::create([
            'event_id' => $event->id,
            'image_path' => 'deposit-img.jpg',
            'material_class' => 'plastic',
            'confidence' => 0.9,
            'points_awarded' => 10,
        ]);
        PendingPairing::create([
            'student_id' => $card->student_id,
            'reader_id' => $reader->id,
            'card_id' => $card->id,
            'expires_at' => now()->addMinute(),
            'consumed_at' => now()->subMinutes(5),
        ]);
        $key = $reader->api_key;

        $response = $this->actingAs($this->admin())
            ->deleteJson("/api/v1/admin/readers/{$reader->id}");

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'deleted' => ['id' => $reader->id, 'label' => $reader->label],
            ]);

        $this->assertDatabaseMissing('readers', ['id' => $reader->id]);
        $this->assertSame(0, PresenceEvent::where('reader_id', $reader->id)->count());
        $this->assertSame(0, RecyclingDeposit::where('event_id', $event->id)->count());
        Storage::disk('local')->assertMissing('deposit-img.jpg');
        $this->assertDatabaseHas('pending_pairings', ['student_id' => $card->student_id, 'reader_id' => null]);

        $frame = RosterUpdate::where('type', 'reader_deleted')->latest('id')->firstOrFail();
        $this->assertSame($reader->id, $frame->payload['id']);

        // The dead key authenticates nothing.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$key}"])->assertUnauthorized();
    }

    #[Test]
    public function the_desk_tucks_rotate_and_delete_behind_the_overflow(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/readers')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('reader-menu', $html);
        $this->assertStringContainsString('popovertarget', $html);
        $this->assertStringContainsString('more_vert', $html);
        $this->assertStringContainsString(__('app.delete_reader'), $html);

        $this->withSession(['locale' => 'es'])
            ->actingAs($this->admin())
            ->get('/admin/readers')
            ->assertOk()
            ->assertSee('Eliminar', false);
    }

    #[Test]
    public function deletion_rejects_guests_teachers_and_unknown_ids(): void
    {
        $reader = Reader::firstOrFail();

        $this->deleteJson("/api/v1/admin/readers/{$reader->id}")->assertUnauthorized();

        $this->actingAs(User::where('email', 'teacher@presence.test')->firstOrFail())
            ->deleteJson("/api/v1/admin/readers/{$reader->id}")
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/admin/readers/999999')
            ->assertNotFound();

        $this->assertDatabaseHas('readers', ['id' => $reader->id]);
    }
}

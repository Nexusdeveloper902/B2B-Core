<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\Reader;
use App\Models\RosterUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030-B (ADR-045) — reader provisioning: create from the GUI with
 * a server-generated key (display-once), rotate a lost/compromised key
 * without SQL. A generated key is PROVEN (it drives a real tap), and
 * the old key is proven dead after rotation. The key appears exactly
 * once per lifecycle event — never in reader objects, frames, the desk
 * HTML, or logs.
 */
class ReaderProvisioningTest extends TestCase
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
    public function an_admin_creates_a_reader_and_gets_the_key_exactly_once(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'Aula 12 — Entrada',
                'type' => 'entry',
                'active_event_type' => 'ENTRY',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'reader' => [
                    'label' => 'Aula 12 — Entrada',
                    'type' => 'entry',
                    'active_event_type' => 'ENTRY',
                ],
            ]);

        $key = $response->json('api_key');
        $this->assertIsString($key);
        $this->assertSame(32, strlen($key));
        $this->assertNotEmpty($response->json('api_key_notice'));

        // The reader OBJECT carries no key (display-once lives only at
        // the top level of the minting response).
        $this->assertArrayNotHasKey('api_key', $response->json('reader'));

        $this->assertDatabaseHas('readers', [
            'label' => 'Aula 12 — Entrada',
            'type' => 'entry',
            'active_event_type' => 'ENTRY',
            'api_key' => $key,
        ]);

        // The birth is announced on the roster channel — without key
        // material.
        $frame = RosterUpdate::where('type', 'reader_created')->latest('id')->firstOrFail();
        $this->assertSame('Aula 12 — Entrada', $frame->payload['label']);
        $this->assertSame('entry', $frame->payload['type']);
        $this->assertStringNotContainsString('api_key', json_encode($frame->payload));
    }

    #[Test]
    public function a_fresh_key_drives_the_real_device_flow(): void
    {
        $key = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'Proving Reader',
                'type' => 'classroom',
                'active_event_type' => 'CLASS_ATTENDANCE',
            ])
            ->assertOk()
            ->json('api_key');

        $card = Card::firstOrFail();

        // Postman-style: nothing but the Bearer key identifies the reader.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$key}"])
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    #[Test]
    public function creation_is_validated_and_mints_nothing_on_failure(): void
    {
        $count = Reader::count();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'AB',
                'type' => 'classroom',
                'active_event_type' => 'CLASS_ATTENDANCE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'Bogus Type Reader',
                'type' => 'teleporter',
                'active_event_type' => 'CLASS_ATTENDANCE',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'Bogus Mode Reader',
                'type' => 'classroom',
                'active_event_type' => 'TIME_TRAVEL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['active_event_type']);

        $this->assertSame($count, Reader::count());
        $this->assertSame(0, RosterUpdate::where('type', 'reader_created')->count());
    }

    #[Test]
    public function guests_and_teachers_never_reach_either_endpoint(): void
    {
        $reader = Reader::firstOrFail();
        $payload = ['label' => 'X-Ray Room', 'type' => 'classroom', 'active_event_type' => 'CLASS_ATTENDANCE'];

        $this->postJson('/api/v1/admin/readers', $payload)->assertUnauthorized();
        $this->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key")->assertUnauthorized();

        $this->actingAs($this->teacher())
            ->postJson('/api/v1/admin/readers', $payload)
            ->assertForbidden();

        $this->actingAs($this->teacher())
            ->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key")
            ->assertForbidden();

        $this->assertSame(2, Reader::count());
    }

    #[Test]
    public function rotating_swaps_the_key_and_kills_the_old_one(): void
    {
        $reader = Reader::firstOrFail();
        $oldKey = $reader->api_key;
        $card = Card::firstOrFail();

        // Finding 16: the frame count snapshots BEFORE the rotation —
        // the assertion below proves ROTATION emits nothing (the old
        // version snapshotted after, proving only that taps are quiet).
        $framesBefore = RosterUpdate::count();

        Log::spy();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key");

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'reader' => ['id' => $reader->id, 'label' => $reader->label],
            ]);

        $fresh = $response->json('api_key');
        $this->assertIsString($fresh);
        $this->assertSame(32, strlen($fresh));
        $this->assertNotSame($oldKey, $fresh);
        $this->assertArrayNotHasKey('api_key', $response->json('reader'));

        // The old key is dead, the new key taps.
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$oldKey}"])->assertUnauthorized();

        // Rotation announces nothing on the roster channel (no
        // displayed state changed).
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$fresh}"])->assertOk();

        $this->assertSame($framesBefore, RosterUpdate::count());

        // The rotation is audited — ids only, never key material.
        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context = []) use ($oldKey, $fresh): bool {
                $blob = $message.json_encode($context);

                return str_contains($message, 'rotated')
                    && ! str_contains($blob, $oldKey)
                    && ! str_contains($blob, $fresh);
            }
        );
    }

    #[Test]
    public function rotating_twice_invalidates_the_middle_key(): void
    {
        $reader = Reader::firstOrFail();

        $first = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key")
            ->assertOk()
            ->json('api_key');

        $second = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key")
            ->assertOk()
            ->json('api_key');

        $this->assertNotSame($first, $second);

        $card = Card::firstOrFail();

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$first}"])->assertUnauthorized();

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $card->credential_uid,
        ], ['Authorization' => "Bearer {$second}"])->assertOk();
    }

    #[Test]
    public function a_client_supplied_key_is_ignored(): void
    {
        // Finding 16: validated() strips api_key — a caller that picks
        // its own secret gets a server-minted one instead.
        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'Sneaky Reader',
                'type' => 'classroom',
                'active_event_type' => 'CLASS_ATTENDANCE',
                'api_key' => 'attacker-chosen-key-00000000000001',
            ]);

        $response->assertOk();

        $stored = Reader::where('label', 'Sneaky Reader')->firstOrFail();
        $this->assertNotSame('attacker-chosen-key-00000000000001', $stored->api_key);
        $this->assertSame($response->json('api_key'), $stored->api_key);
    }

    #[Test]
    public function two_creates_mint_distinct_keys(): void
    {
        $payload = fn (string $label): array => [
            'label' => $label,
            'type' => 'classroom',
            'active_event_type' => 'CLASS_ATTENDANCE',
        ];

        $first = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', $payload('Twin Uno'))
            ->assertOk()
            ->json('api_key');

        $second = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', $payload('Twin Dos'))
            ->assertOk()
            ->json('api_key');

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function labels_may_repeat_but_validation_still_bites(): void
    {
        // Pinned decision: labels are display names, not identity (the
        // key is) — duplicates are allowed, short/missing fields are not.
        $payload = [
            'label' => 'Demo Reader — Classroom/PAE',
            'type' => 'classroom',
            'active_event_type' => 'CLASS_ATTENDANCE',
        ];

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', $payload)
            ->assertOk();

        // Exactly 3 chars passes the min:3 boundary.
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'ABC',
                'type' => 'entry',
                'active_event_type' => 'EXIT',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'No Type Reader',
                'active_event_type' => 'ENTRY',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers', [
                'label' => 'No Mode Reader',
                'type' => 'entry',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['active_event_type']);
    }

    #[Test]
    public function a_rotated_recycling_key_gates_the_classify_flow(): void
    {
        // Finding 16: the Bearer gates every device endpoint, not just
        // taps — prove it on the classify flow with a recycling reader.
        $reader = Reader::where('type', 'recycling')->firstOrFail();
        $oldKey = $reader->api_key;

        $fresh = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/readers/{$reader->id}/rotate-key")
            ->assertOk()
            ->json('api_key');

        $this->postJson('/api/v1/recycling/classify', [
            'event_id' => 1,
        ], ['Authorization' => "Bearer {$oldKey}"])->assertUnauthorized();

        // The fresh key authenticates (the request sails past auth
        // into endpoint validation — 422 for the nonexistent event,
        // not 401).
        $this->postJson('/api/v1/recycling/classify', [
            'event_id' => 1,
        ], ['Authorization' => "Bearer {$fresh}"])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_id']);
    }

    #[Test]
    public function rotating_an_unknown_reader_is_a_404(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/readers/999999/rotate-key')
            ->assertNotFound();
    }

    #[Test]
    public function the_desk_shows_the_workflow_but_never_a_key(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/readers')
            ->assertOk()
            ->assertSee(__('app.create_reader'), false)
            ->assertSee(__('app.rotate_key'), false)
            ->getContent();

        foreach (Reader::pluck('api_key') as $key) {
            $this->assertStringNotContainsString($key, $html);
        }
    }

    #[Test]
    public function the_desk_renders_in_spanish(): void
    {
        $this->withSession(['locale' => 'es'])
            ->actingAs($this->admin())
            ->get('/admin/readers')
            ->assertOk()
            ->assertSee('Añadir lector', false)
            ->assertSee('Rotar clave', false);
    }
}

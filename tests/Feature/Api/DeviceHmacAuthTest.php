<?php

namespace Tests\Feature\Api;

use App\Contracts\MaterialClassifier;
use App\Models\PresenceEvent;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-043 — signed device authentication (RT-001 fix, ADR-062).
 *
 * The reader secret never rides the wire: every request carries
 * `Authorization: Pulse-HMAC <kid>:<nonce>:<sig>`. These tests prove,
 * over real HTTP through the real middleware:
 *  - a legitimately signed device authenticates (all workflows work);
 *  - wrong secrets / tampered bodies / unknown fingerprints fail closed;
 *  - a captured request replayed verbatim is rejected (single-use nonce);
 *  - stateless reconnects (fresh nonce per request, new IP irrelevant) work;
 *  - the legacy bench Bearer still works — and can be locked down.
 */
class DeviceHmacAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        PresenceEvent::query()->delete();
    }

    private function signedTap(string $readerType, string $uid, ?string $nonce = null, ?string $secret = null): TestResponse
    {
        $reader = $this->reader($readerType);
        $body = json_encode(['credential_uid' => $uid]);
        $nonce ??= Str::random(32);
        $secret ??= $reader->api_key;

        $sig = DeviceRequestSigner::sign($secret, 'POST', '/api/v1/events/tap', $body, $nonce);
        $kid = DeviceRequestSigner::fingerprint($secret);

        return $this->call(
            'POST',
            '/api/v1/events/tap',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => "Pulse-HMAC {$kid}:{$nonce}:{$sig}",
            ],
            $body
        );
    }

    #[Test]
    public function a_legitimately_signed_device_taps_successfully(): void
    {
        $response = $this->signedTap('classroom', $this->cardUidFor('Maria González'));

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'CLASS_ATTENDANCE', 'duplicate' => false]);

        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function a_wrong_secret_is_rejected_without_touching_state(): void
    {
        $response = $this->signedTap('classroom', $this->cardUidFor('Maria González'), secret: 'wrong-secret-000000000000000001');

        $response->assertUnauthorized()
            ->assertJson(['status' => 'error', 'message' => 'Invalid device signature']);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_tampered_body_invalidates_the_captured_signature(): void
    {
        // Sign body A, send body B with the same header: the signature is
        // body-bound, so the request dies at the middleware — before any
        // card lookup, before any write.
        $reader = $this->reader('classroom');
        $nonce = Str::random(32);
        $sig = DeviceRequestSigner::sign($reader->api_key, 'POST', '/api/v1/events/tap', json_encode(['credential_uid' => 'AAA']), $nonce);

        $response = $this->call(
            'POST',
            '/api/v1/events/tap',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Pulse-HMAC '.DeviceRequestSigner::fingerprint($reader->api_key).":{$nonce}:{$sig}",
            ],
            json_encode(['credential_uid' => $this->cardUidFor('Maria González')])
        );

        $response->assertUnauthorized()
            ->assertJson(['status' => 'error']);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function a_captured_request_replayed_verbatim_is_rejected(): void
    {
        // The exact bytes of a legitimate request, captured off the wire
        // and replayed: the single-use nonce is already spent.
        $reader = $this->reader('classroom');
        $body = json_encode(['credential_uid' => $this->cardUidFor('Maria González')]);
        $nonce = Str::random(32);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($reader, 'POST', '/api/v1/events/tap', $body, $nonce),
        ];

        $first = $this->call('POST', '/api/v1/events/tap', [], [], [], $server, $body);
        $first->assertOk();
        $this->assertDatabaseCount('events', 1);

        $replay = $this->call('POST', '/api/v1/events/tap', [], [], [], $server, $body);
        $replay->assertUnauthorized()
            ->assertJson(['status' => 'error', 'message' => 'Invalid device signature']);
        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function reconnects_are_stateless_fresh_nonce_per_request_always_passes(): void
    {
        // A device that changed IP, rebooted, or re-discovered the backend
        // carries no session: every request is self-contained, so ten
        // sequential taps (distinct students cycle, distinct nonces) all
        // authenticate — topology changes are invisible to the scheme.
        $uids = [
            $this->cardUidFor('Maria González'),
            $this->cardUidFor('Carlos Pérez'),
            $this->cardUidFor('Ana Martínez'),
        ];

        for ($i = 0; $i < 9; $i++) {
            $this->signedTap('classroom', $uids[$i % 3])->assertOk();
        }

        // 3 first-taps counted; the 6 same-day repeats collapse (RT-002).
        $this->assertDatabaseCount('events', 3);
    }

    #[Test]
    public function the_legacy_bench_bearer_still_works(): void
    {
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function the_legacy_bearer_can_be_locked_down_to_signatures_only(): void
    {
        config(['presence.device_auth.allow_legacy_bearer' => false]);

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])
            ->assertUnauthorized();

        $this->signedTap('classroom', $this->cardUidFor('Maria González'))->assertOk();

        $this->assertDatabaseCount('events', 1);
    }

    #[Test]
    public function unknown_fingerprints_and_malformed_headers_fail_closed(): void
    {
        $body = json_encode(['credential_uid' => $this->cardUidFor('Maria González')]);
        $serverFor = fn (string $auth): array => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $auth,
        ];

        // Fingerprint of a secret no reader holds.
        $this->call('POST', '/api/v1/events/tap', [], [], [], $serverFor(
            'Pulse-HMAC deadbeefdeadbeef:'.Str::random(32).':'.str_repeat('0', 64)
        ), $body)->assertUnauthorized();

        // Truncated / garbage shapes.
        $this->call('POST', '/api/v1/events/tap', [], [], [], $serverFor('Pulse-HMAC short'), $body)
            ->assertUnauthorized();
        $this->call('POST', '/api/v1/events/tap', [], [], [], $serverFor('Pulse-HMAC :'.Str::random(32).':'.str_repeat('0', 64)), $body)
            ->assertUnauthorized();

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function the_wire_format_is_pinned_for_firmware_interop(): void
    {
        // Golden vector — hardcoded, NOT self-computed: the ESP32 signer
        // (test_request_signer.cpp) pins these exact literals. Any drift
        // on either side breaks interop loudly instead of silently 401ing
        // fielded readers.
        $body = '{"credential_uid":"QHHIKKOSD6UU"}';

        $this->assertSame(
            'POST'."\n".'/api/v1/events/tap'."\n".'0123456789abcdef'."\n".'56d9e707d82e2d35fce5841b43c860a3b86a49336f04f96023782a52d8f66fb6',
            DeviceRequestSigner::canonical('POST', '/api/v1/events/tap', '0123456789abcdef', $body)
        );

        $this->assertSame(
            '4ec7367d8b340da73914b806992ac6544cc54c9887777cddc6f827b02566c4f1',
            DeviceRequestSigner::sign('test-secret-000000000000000001', 'POST', '/api/v1/events/tap', $body, '0123456789abcdef')
        );

        $this->assertSame(
            'b46b73a6f9444ea4',
            DeviceRequestSigner::fingerprint('test-secret-000000000000000001')
        );
    }

    #[Test]
    public function the_multipart_canonical_format_is_pinned_for_firmware_interop(): void
    {
        // Byte-exact mirror of firmware CapturePayload signing bodies.
        // sha256('abc') = ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad.
        $this->assertSame(
            "event_id=421\nimage.sha256=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
            DeviceRequestSigner::multipartCanonical('421', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
        );
        $this->assertSame(
            'image.sha256=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            DeviceRequestSigner::multipartCanonical(null, 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
        );
        // Event ids normalize to plain ints (device sends to_string, no padding).
        $this->assertSame(
            DeviceRequestSigner::multipartCanonical('421', 'aa'),
            DeviceRequestSigner::multipartCanonical('0421', 'aa')
        );
    }

    #[Test]
    public function a_signed_device_classifies_with_the_multipart_canonical(): void
    {
        Storage::fake('local');
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'plastic', 'confidence' => 0.87];
            }
        });

        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);
        $reader = $this->reader('recycling');
        $file = UploadedFile::fake()->image('bottle.jpg');
        $hash = hash_file('sha256', $file->getPathname());
        $canonical = DeviceRequestSigner::multipartCanonical((string) $event->id, $hash);
        $nonce = Str::random(32);

        $response = $this->call(
            'POST',
            '/api/v1/recycling/classify',
            ['event_id' => $event->id],
            [],
            ['image' => $file],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($reader, 'POST', '/api/v1/recycling/classify', $canonical, $nonce),
            ]
        );

        $response->assertOk()->assertJson(['status' => 'ok', 'material_class' => 'plastic']);
        $this->assertDatabaseHas('recycling_deposits', ['event_id' => $event->id]);
    }

    #[Test]
    public function a_swapped_image_invalidates_the_multipart_signature(): void
    {
        Storage::fake('local');
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'plastic', 'confidence' => 0.9];
            }
        });

        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);
        $reader = $this->reader('recycling');
        $signedFile = UploadedFile::fake()->image('signed.jpg');
        $hash = hash_file('sha256', $signedFile->getPathname());
        $canonical = DeviceRequestSigner::multipartCanonical((string) $event->id, $hash);
        $nonce = Str::random(32);
        $header = DeviceRequestSigner::authorizationHeader($reader, 'POST', '/api/v1/recycling/classify', $canonical, $nonce);

        // Same header, DIFFERENT image bytes: body-bound, must fail closed.
        $otherFile = UploadedFile::fake()->image('other.jpg', 800, 600);
        $this->call(
            'POST',
            '/api/v1/recycling/classify',
            ['event_id' => $event->id],
            [],
            ['image' => $otherFile],
            ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $header]
        )->assertUnauthorized()->assertJson(['status' => 'error', 'message' => 'Invalid device signature']);

        $this->assertDatabaseCount('recycling_deposits', 0);
    }

    #[Test]
    public function a_signed_device_captures_bottle_first_with_the_multipart_canonical(): void
    {
        Storage::fake('local');
        $reader = $this->reader('recycling');
        $file = UploadedFile::fake()->image('bottle.jpg');
        $hash = hash_file('sha256', $file->getPathname());
        $canonical = DeviceRequestSigner::multipartCanonical(null, $hash);
        $nonce = Str::random(32);

        $this->call(
            'POST',
            '/api/v1/recycling/capture',
            [],
            [],
            ['image' => $file],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => DeviceRequestSigner::authorizationHeader($reader, 'POST', '/api/v1/recycling/capture', $canonical, $nonce),
            ]
        )->assertOk()->assertJson(['status' => 'ok', 'state' => 'awaiting_card']);
    }
}

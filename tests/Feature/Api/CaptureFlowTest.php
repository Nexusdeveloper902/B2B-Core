<?php

namespace Tests\Feature\Api;

use App\Contracts\MaterialClassifier;
use App\Enums\PendingCaptureState;
use App\Models\PendingCapture;
use App\Models\PointsLedger;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-025 item 2 — the bottle-first flow end-to-end (spec §3 Case B,
 * §5, §32): capture WITHOUT a card, associate with a card, points.
 * Includes the two honesty proofs the owner's spec demands:
 *   - cost gate: NO classifier call before student association
 *   - expiry: a pending capture times out with NO award and no leak
 */
class CaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();

        // A deterministic classifier that COUNTS its calls — the cost
        // gate is asserted from call counts, not from absence of rows.
        $this->classifierCalls = 0;
        $fake = new class($this) implements MaterialClassifier
        {
            public function __construct(private CaptureFlowTest $test) {}

            public function classify(string $imagePath): array
            {
                $this->test->classifierCalls++;

                return ['material_class' => 'plastic', 'confidence' => 0.9, 'is_bottle' => true, 'is_recyclable' => true];
            }
        };
        $this->swap(MaterialClassifier::class, $fake);

        Storage::fake('local');
    }

    public int $classifierCalls = 0;

    #[Test]
    public function a_capture_without_a_card_is_held_awaiting_card_with_no_classifier_call(): void
    {
        $response = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'state' => 'awaiting_card',
                'next_step' => 'present_card',
            ]);

        $captureId = $response->json('capture_id');

        // The image is persisted (spec §14) and referenced.
        $capture = PendingCapture::find($captureId);
        $this->assertNotNull($capture);
        Storage::disk('local')->assertExists($capture->image_path);

        // The cost gate (spec §4): nothing reached the classifier — no
        // student exists yet, so no vision-API call may have happened.
        $this->assertSame(0, $this->classifierCalls);

        // No points moved, no deposit exists.
        $this->assertSame(0, PointsLedger::count());
        $this->assertSame(0, RecyclingDeposit::count());

        // The capture_created frame was recorded (committed).
        $this->assertDatabaseHas('recycling_updates', [
            'type' => RecyclingUpdate::TYPE_CAPTURE_CREATED,
        ]);
    }

    #[Test]
    public function a_card_association_resolves_the_capture_and_awards_points(): void
    {
        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->json('capture_id');

        $uid = $this->cardUidFor('Maria González');

        $response = $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'capture_state' => 'accepted',
                'already_classified' => false,
                'material_class' => 'plastic',
                'is_bottle' => true,
                'is_recyclable' => true,
                'points_awarded' => 10,
                'new_balance' => 10,
            ]);

        // The classifier ran EXACTLY once — at association (after the
        // student was known), never at capture.
        $this->assertSame(1, $this->classifierCalls);

        // The deposit carries the tap event + the persisted image.
        $capture = PendingCapture::find($captureId);
        $this->assertNotNull($capture->event_id);
        $this->assertNotNull($capture->card_id);
        $this->assertSame(PendingCaptureState::Accepted, $capture->state);
        $this->assertDatabaseHas('recycling_deposits', [
            'event_id' => $capture->event_id,
            'image_path' => $capture->image_path,
            'is_bottle' => true,
        ]);

        // One ledger row, one student.
        $this->assertSame(1, PointsLedger::count());
        $this->assertDatabaseHas('points_ledger', [
            'student_id' => $capture->card->student_id,
            'delta' => 10,
        ]);

        // The full frame chain is recorded: validation_started ->
        // validated -> points_awarded -> leaderboard_updated.
        $types = RecyclingUpdate::orderBy('id')->pluck('type')->all();
        $this->assertContains(RecyclingUpdate::TYPE_VALIDATION_STARTED, $types);
        $this->assertContains(RecyclingUpdate::TYPE_VALIDATED, $types);
        $this->assertContains(RecyclingUpdate::TYPE_POINTS_AWARDED, $types);
        $this->assertContains(RecyclingUpdate::TYPE_LEADERBOARD_UPDATED, $types);
    }

    #[Test]
    public function an_expired_capture_awards_nothing_and_leaks_nothing(): void
    {
        config(['recycling.capture.ttl_seconds' => 60]);

        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->json('capture_id');

        // Time passes beyond the window.
        $this->travel(61)->seconds();

        $uid = $this->cardUidFor('Carlos Pérez');

        $response = $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'reason' => 'no_pending_capture',
            ]);

        // The capture was swept to expired; NO award happened.
        $this->assertSame(PendingCaptureState::Expired, PendingCapture::find($captureId)->state);
        $this->assertSame(0, $this->classifierCalls);
        $this->assertSame(0, PointsLedger::count());
        $this->assertSame(0, RecyclingDeposit::count());
        $this->assertSame(0, RecyclingUpdate::where('type', RecyclingUpdate::TYPE_POINTS_AWARDED)->count());
    }

    #[Test]
    public function an_unknown_card_keeps_the_window_open(): void
    {
        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->json('capture_id');

        $response = $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => 'DOESNOTEXIST',
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'reason' => 'card_not_found',
            ]);

        // The window is NOT terminal: still awaiting the right card.
        $capture = PendingCapture::find($captureId);
        $this->assertSame(PendingCaptureState::AwaitingCard, $capture->state);
        $this->assertTrue($capture->isUsable());
    }

    #[Test]
    public function another_readers_capture_cannot_be_associated(): void
    {
        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->json('capture_id');

        $uid = $this->cardUidFor('Maria González');

        // The CLASSROOM reader tries to resolve the recycling station's
        // capture: 403, capture untouched.
        $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])
            ->assertForbidden()
            ->assertJson([
                'status' => 'error',
                'reason' => 'not_owned',
            ]);

        $this->assertSame(PendingCaptureState::AwaitingCard, PendingCapture::find($captureId)->state);
    }

    #[Test]
    public function a_resolved_capture_cannot_be_associated_again(): void
    {
        $captureId = $this->post('/api/v1/recycling/capture', [
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->json('capture_id');

        $uid = $this->cardUidFor('Maria González');

        $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $uid,
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertOk();

        // A second association attempt (device retry with a different
        // card, say) must not award twice — the capture is terminal.
        $other = $this->cardUidFor('Diego López');

        $this->postJson("/api/v1/recycling/captures/{$captureId}/associate", [
            'credential_uid' => $other,
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertNotFound()
            ->assertJson([
                'status' => 'error',
                'reason' => 'no_pending_capture',
                'capture_state' => 'accepted',
            ]);

        $this->assertSame(1, $this->classifierCalls);
        $this->assertSame(1, PointsLedger::count());
    }
}

<?php

namespace Tests\Feature\Api;

use App\Contracts\MaterialClassifier;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Services\Recycling\ClassificationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function classification_awards_points_per_the_configured_table(): void
    {
        // Bind a deterministic fake classifier (the contract swap the
        // architecture promises — no controller changes needed).
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'plastic', 'confidence' => 0.87];
            }
        });

        $event = $this->recyclingTapFor('Maria González');
        $student = $this->cardOf('Maria González')->student;

        $response = $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'already_classified' => false,
                'material_class' => 'plastic',
                'confidence' => 0.87,
                'points_awarded' => 10, // config: plastic = 10
                'new_balance' => 10,
            ]);

        $this->assertDatabaseHas('points_ledger', [
            'student_id' => $student->id,
            'delta' => 10,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);
    }

    #[Test]
    public function re_submitting_the_same_event_does_not_double_award_points(): void
    {
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                return ['material_class' => 'metal', 'confidence' => 0.9];
            }
        });

        $event = $this->recyclingTapFor('Carlos Pérez');

        $first = $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('can.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $second = $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('can-retry.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')]);

        $first->assertOk()->assertJson(['status' => 'ok', 'already_classified' => false, 'points_awarded' => 15]);
        $second->assertOk()->assertJson(['status' => 'ok', 'already_classified' => true, 'points_awarded' => 15]);

        // Exactly one deposit and one ledger row — the idempotency guarantee.
        $this->assertSame(1, RecyclingDeposit::where('event_id', $event->id)->count());
        $this->assertSame(
            1,
            PointsLedger::where('event_id', $event->id)->count()
        );
        $this->assertSame(15, (int) PointsLedger::where('event_id', $event->id)->sum('delta'));
    }

    #[Test]
    public function a_reader_cannot_classify_another_readers_event(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        // Classroom reader tries to classify the recycling reader's event.
        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('evil.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])
            ->assertForbidden()
            ->assertJson(['status' => 'error']);

        $this->assertDatabaseCount('recycling_deposits', 0);
    }

    #[Test]
    public function non_recycling_events_cannot_be_classified(): void
    {
        // An event on the recycling reader while it was relabeled away
        // from RECYCLING_DEPOSIT (mode mismatch) is not classifiable.
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now(),
        ]);

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('x.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertUnprocessable()
            ->assertJson(['status' => 'error']);
    }

    #[Test]
    public function missing_image_is_a_validation_error(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertUnprocessable();
    }

    #[Test]
    public function an_unreachable_local_model_returns_503_and_awards_nothing(): void
    {
        // Simulate the intended production driver being down.
        $this->swap(MaterialClassifier::class, new class implements MaterialClassifier
        {
            public function classify(string $imagePath): array
            {
                throw ClassificationException::driverUnavailable('local', 'connection refused');
            }
        });

        $event = $this->recyclingTapFor('Maria González');

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => 'Bearer '.$this->readerToken('recycling')])
            ->assertStatus(503)
            ->assertJson(['status' => 'error']);

        $this->assertDatabaseCount('recycling_deposits', 0);
        $this->assertDatabaseCount('points_ledger', 0);
    }

    #[Test]
    public function an_invalid_bearer_token_is_rejected(): void
    {
        $event = $this->recyclingTapFor('Maria González');

        $this->post('/api/v1/recycling/classify', [
            'event_id' => $event->id,
            'image' => UploadedFile::fake()->image('x.jpg'),
        ], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    private function recyclingTapFor(string $studentName): PresenceEvent
    {
        return PresenceEvent::create([
            'card_id' => $this->cardOf($studentName)->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now(),
        ]);
    }
}

<?php

namespace Tests\E2E;

use App\Contracts\MaterialClassifier;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reward;
use App\Services\NlQuery\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E — the complete platform story, exactly as a demo day would run it:
 * simulate a school morning with card taps, a recycling deposit with
 * classification, a reward redemption, reader relabeling, dashboards,
 * the parent timeline, and the NL-query blocked-state check.
 *
 * Everything goes through real HTTP endpoints (kernel-level), with the
 * demo seeder data and the same drivers a production instance would use
 * (only the classifier is faked deterministically to keep assertions
 * exact without model dependencies).
 */
class FullJourneyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_complete_school_day(): void
    {
        // ---- The world exists (seeder = demo data) ----
        $fixtures = $this->seedDemo();
        $admin = $fixtures['admin'];
        $teacher = $fixtures['teacher'];
        $classroomKey = $this->readerToken('classroom');
        $recyclingKey = $this->readerToken('recycling');

        // ---- Morning: attendance taps (devices authenticate with Bearer) ----
        $mariaTap = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => "Bearer {$classroomKey}"]);
        $mariaTap->assertOk();
        $this->assertSame('CLASS_ATTENDANCE', $mariaTap->json('event_type'));

        $carlosTap = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Carlos Pérez'),
        ], ['Authorization' => "Bearer {$classroomKey}"]);
        $carlosTap->assertOk();

        // An unknown card is rejected without side effects.
        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'INTRUDER'], [
            'Authorization' => "Bearer {$classroomKey}",
        ])->assertNotFound();
        $this->assertSame(2, PresenceEvent::count());

        // ---- The reader gets relabeled to PAE breakfast (admin action) ----
        $readerId = $this->reader('classroom')->id;
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/readers/{$readerId}/mode", ['active_event_type' => 'PAE_BREAKFAST'])
            ->assertOk()
            ->assertJsonPath('reader.active_event_type', 'PAE_BREAKFAST');

        // ---- Breakfast service: the same reader, now in PAE mode ----
        foreach (['Maria González', 'Carlos Pérez', 'Diego López'] as $name) {
            $this->postJson('/api/v1/events/tap', [
                'credential_uid' => $this->cardUidFor($name),
            ], ['Authorization' => "Bearer {$classroomKey}"])
                ->assertOk()
                ->assertJsonPath('event_type', 'PAE_BREAKFAST');
        }

        // ---- Recycling: tap -> classify -> earn (with a fixed classifier) ----
        // One mutable fake, bound BEFORE the first request (the controller
        // dependency graph is resolved once per route — see ADR-003).
        $fakeClassifier = new class implements MaterialClassifier
        {
            public string $material = 'plastic';

            public float $confidence = 0.92;

            public function classify(string $imagePath): array
            {
                return ['material_class' => $this->material, 'confidence' => $this->confidence];
            }
        };
        $this->swap(MaterialClassifier::class, $fakeClassifier);

        $mariaRecycling = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => "Bearer {$recyclingKey}"]);
        $mariaRecycling->assertOk()
            ->assertJsonPath('next_step', 'awaiting_classification');

        $eventId = $mariaRecycling->json('event_id');

        $classify = $this->post('/api/v1/recycling/classify', [
            'event_id' => $eventId,
            'image' => UploadedFile::fake()->image('bottle.jpg'),
        ], ['Authorization' => "Bearer {$recyclingKey}"]);

        $classify->assertOk()
            ->assertJson([
                'status' => 'ok',
                'material_class' => 'plastic',
                'points_awarded' => 10,
                'new_balance' => 10,
            ]);

        // A device retry after a network blip must not double-award.
        $retry = $this->post('/api/v1/recycling/classify', [
            'event_id' => $eventId,
            'image' => UploadedFile::fake()->image('bottle-retry.jpg'),
        ], ['Authorization' => "Bearer {$recyclingKey}"]);
        $retry->assertOk()->assertJson(['already_classified' => true, 'new_balance' => 10]);

        // Maria recycles a metal can too (+15).
        $secondTap = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $this->cardUidFor('Maria González'),
        ], ['Authorization' => "Bearer {$recyclingKey}"]);
        $fakeClassifier->material = 'metal';
        $fakeClassifier->confidence = 0.81;
        $this->post('/api/v1/recycling/classify', [
            'event_id' => $secondTap->json('event_id'),
            'image' => UploadedFile::fake()->image('can.jpg'),
        ], ['Authorization' => "Bearer {$recyclingKey}"])
            ->assertOk()
            ->assertJson(['points_awarded' => 15, 'new_balance' => 25]);

        // ---- Redemption: the spend half of the loop ----
        $studentId = $this->cardOf('Maria González')->student->id;
        $raffle = Reward::where('point_cost', 20)->firstOrFail();

        // Carlos (0 points) cannot redeem.
        $carlosId = $this->cardOf('Carlos Pérez')->student->id;
        $this->actingAs($admin)
            ->postJson("/api/v1/students/{$carlosId}/redeem", ['reward_id' => $raffle->id])
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'shortfall' => 20]);

        // Maria (25 points) can.
        $this->actingAs($admin)
            ->postJson("/api/v1/students/{$studentId}/redeem", ['reward_id' => $raffle->id])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'new_balance' => 5]);

        // ---- Dashboards render the day ----
        $this->actingAs($teacher)->get('/teacher')->assertOk()->assertSee('Maria González');
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Demo Reader — Recycling');

        // ---- Parent view: the whole story on one timeline ----
        $timeline = $this->actingAs($admin)->get("/parent/students/{$studentId}");
        $timeline->assertOk()
            ->assertSee('CLASS_ATTENDANCE')
            ->assertSee('PAE_BREAKFAST')
            ->assertSee('RECYCLING_DEPOSIT')
            ->assertSee('plastic')
            ->assertSee('metal');

        // ---- NL query: honest blocked state when no key is configured ----
        config(['recycling.nl_query.api_key' => null]);
        $this->app->forgetInstance(GeminiClient::class);

        $this->actingAs($admin)
            ->postJson('/api/v1/nl-query', ['question' => 'How many students attended today?'])
            ->assertStatus(503)
            ->assertJson(['status' => 'blocked', 'blocked_reason' => 'missing_llm_credential']);

        // ---- Invariant: the ledger tells the whole earn+spend story ----
        $ledger = PointsLedger::orderBy('id')->get();
        $this->assertSame(
            [10, 15, -20],
            $ledger->where('student_id', $studentId)->pluck('delta')->all(),
            'Ledger must show earn + earn + spend in order.'
        );
        $this->assertSame(5, (int) $ledger->where('student_id', $studentId)->sum('delta'));
    }
}

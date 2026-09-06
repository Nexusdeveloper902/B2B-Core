<?php

namespace Tests\Unit\Realtime;

use App\Models\Student;
use App\Services\PairingService;
use App\Services\Realtime\RealtimePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RealtimePairingTest — the pairing channel's change detector (TASK-020,
 * ADR-029).
 *
 * Pins the two promises the broadcast makes: (1) the signature is a
 * fingerprint of the ROW data — it changes exactly when the desk's state
 * inputs change (arm / consume / reject), and NOT when time merely
 * passes; (2) the payload is PairingService::statusPayload() verbatim —
 * the WS path and the REST status path can never disagree.
 */
class RealtimePairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function channel(): RealtimePairing
    {
        return $this->app->make(RealtimePairing::class);
    }

    private function pairings(): PairingService
    {
        return $this->app->make(PairingService::class);
    }

    #[Test]
    public function the_signature_is_stable_while_nothing_changes(): void
    {
        // A countdown ticking is a time-only transition, NOT a row change:
        // the desk's own client clock owns the passing seconds (ADR-029).
        $channel = $this->channel();

        $first = $channel->signature();
        $this->assertNotSame('', $first);

        $this->assertSame($first, $channel->signature(), 'an idle pairing table must not re-broadcast');
    }

    #[Test]
    public function arming_changes_the_signature(): void
    {
        $channel = $this->channel();
        $before = $channel->signature();

        $this->pairings()->arm(Student::orderBy('id')->first());

        $this->assertNotSame($before, $channel->signature(), 'arming must be broadcast');
    }

    #[Test]
    public function consuming_and_rejecting_change_the_signature(): void
    {
        $channel = $this->channel();
        $pairings = $this->pairings();
        $target = Student::whereDoesntHave('cards')->first()
            ?? Student::create(['name' => 'Fresh Target', 'grade' => '5°', 'pae_enrolled' => false]);

        // A rejection stamp mutates the ARMED row — it must broadcast.
        $pairings->arm($target);
        $armed = $channel->signature();
        $pairings->pair($this->reader('classroom'), $this->cardUidFor('Maria González'));
        $this->assertNotSame($armed, $channel->signature(), 'a rejected tap must re-broadcast (TASK-014 note)');

        // A consumption closes the loop — it must broadcast.
        $rejected = $channel->signature();
        $pairings->pair($this->reader('classroom'), 'SIGFRESH0001');
        $this->assertNotSame($rejected, $channel->signature(), 'a consumed pairing must broadcast');
    }

    #[Test]
    public function the_payload_is_the_shared_status_truth(): void
    {
        $pairings = $this->pairings();
        $target = Student::whereDoesntHave('cards')->first()
            ?? Student::create(['name' => 'Payload Target', 'grade' => '5°', 'pae_enrolled' => false]);
        $pairings->arm($target);
        $pairings->pair($this->reader('classroom'), 'PAYLOADFRESH1');

        // The frame payload and PairingService::statusPayload() are the
        // same array — the REST endpoint wraps it with status:ok, nothing
        // more (PairingStatusController composes the same way).
        $this->assertSame(
            $pairings->statusPayload(),
            $this->channel()->payload(),
        );

        // Spot-check the shape the desk script consumes.
        $payload = $this->channel()->payload();
        $this->assertNull($payload['pending']);
        $this->assertSame('PAYLOADFRESH1', $payload['last_pairing']['card_uid']);
        $this->assertSame($target->name, $payload['recent_pairings'][0]['student_name']);
    }
}

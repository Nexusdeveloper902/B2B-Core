<?php

namespace Tests\Feature\Console;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-013 — `cards:unpair` / `./run unpair` (testing reset, ADR-023).
 *
 * Pins the semantics the bench needs: "unpair" deletes the cards rows
 * (freshness = row non-existence — clearing student_id would NOT make a
 * card re-pairable, ADR-020 invariant 2), cascades their tap events,
 * clears the pairing-history card links while the history rows survive,
 * and leaves students / readers / users untouched. The loop test mirrors
 * the owner's actual scenario: pair a card, unpair everything, re-pair
 * the SAME credential_uid to another student.
 */
class UnpairCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function force_unpair_deletes_every_card_and_event_and_clears_history_links_but_keeps_everything_else(): void
    {
        $this->seedDemo();

        // A completed pairing (arm + pair) + one tap event on the new card,
        // plus the seeded cards and any seeded events: the full card surface.
        $student = Student::whereDoesntHave('cards')->first()
            ?? Student::create(['name' => 'Estudiante Nueva', 'grade' => '5°', 'pae_enrolled' => false]);
        $this->actingAs(User::where('email', 'admin@presence.test')->firstOrFail())
            ->postJson("/api/v1/admin/students/{$student->id}/arm-pairing");
        $this->postJson('/api/v1/admin/cards/pair', [
            'credential_uid' => 'FRESHCARD01',
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])->assertOk();

        // Baselines AFTER the fixtures exist: they measure what the UNPAIR
        // itself touches — everything else must be untouched.
        [$studentsBefore, $readersBefore, $usersBefore] = [
            Student::count(), Reader::count(), User::count(),
        ];

        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => 'FRESHCARD01',
        ], ['Authorization' => 'Bearer '.$this->readerToken('classroom')])->assertOk();

        $cardsBefore = Card::count();
        $eventsBefore = PresenceEvent::count();
        $linksBefore = PendingPairing::whereNotNull('card_id')->count();
        $this->assertGreaterThan(0, $cardsBefore);
        $this->assertGreaterThan(0, $eventsBefore);
        $this->assertGreaterThan(0, $linksBefore);

        Artisan::call('cards:unpair', ['--force' => true]);

        $this->assertSame(0, Card::count(), 'every cards row must be gone');
        $this->assertSame(0, PresenceEvent::count(), 'every tap event belongs to a card — all cascade');
        $this->assertSame(0, PendingPairing::whereNotNull('card_id')->count(), 'history card links cleared');
        $this->assertGreaterThan(0, PendingPairing::count(), 'pairing history ROWS survive (audit trail)');
        $this->assertSame($studentsBefore, Student::count(), 'students untouched');
        $this->assertSame($readersBefore, Reader::count(), 'readers untouched');
        $this->assertSame($usersBefore, User::count(), 'users untouched');
    }

    #[Test]
    public function the_bench_loop_pair_unpair_and_repair_the_same_credential(): void
    {
        $this->seedDemo();

        $first = Student::create(['name' => 'Estudiante Uno', 'grade' => '5°', 'pae_enrolled' => false]);
        $second = Student::create(['name' => 'Estudiante Dos', 'grade' => '5°', 'pae_enrolled' => false]);
        $admin = User::where('email', 'admin@presence.test')->firstOrFail();
        $readerHeaders = ['Authorization' => 'Bearer '.$this->readerToken('classroom')];
        $uid = '62041607'; // the owner's bench card

        // 1. Pair it once (arm + pair) — the normal flow.
        $this->actingAs($admin)->postJson("/api/v1/admin/students/{$first->id}/arm-pairing")->assertOk();
        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => $uid], $readerHeaders)->assertOk();

        // 2. The card is now burned: a second pairing attempt is rejected.
        $this->actingAs($admin)->postJson("/api/v1/admin/students/{$second->id}/arm-pairing")->assertOk();
        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => $uid], $readerHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Card already paired');
        $this->assertNotNull(Card::where('credential_uid', $uid)->first());

        // 3. Unpair everything — the credential becomes fresh again.
        Artisan::call('cards:unpair', ['--force' => true]);
        $this->assertNull(Card::where('credential_uid', $uid)->first());

        // 4. The SAME credential_uid now pairs to the other student, and
        //    the re-paired card starts with a clean event history.
        $this->postJson('/api/v1/admin/cards/pair', ['credential_uid' => $uid], $readerHeaders)->assertOk();
        $card = Card::where('credential_uid', $uid)->firstOrFail();
        $this->assertSame($second->id, $card->student_id);
        $this->assertSame(0, $card->events()->count(), 'the old card row (and its events) is gone — history starts fresh');
    }

    #[Test]
    public function without_force_the_confirmation_prompt_guards_the_delete(): void
    {
        $this->seedDemo();
        $cardsBefore = Card::count();
        $this->assertGreaterThan(0, $cardsBefore);

        // Declining the prompt: nothing changes.
        $this->artisan('cards:unpair')
            ->expectsConfirmation('Proceed? / ¿Continuar?', 'no')
            ->assertSuccessful();
        $this->assertSame($cardsBefore, Card::count());

        // Accepting the prompt: the delete runs without --force too.
        $this->artisan('cards:unpair')
            ->expectsConfirmation('Proceed? / ¿Continuar?', 'yes')
            ->assertSuccessful();
        $this->assertSame(0, Card::count());
    }

    #[Test]
    public function output_is_bilingual_with_honest_counts(): void
    {
        $this->seedDemo();
        $cards = Card::count();
        $events = PresenceEvent::count();

        Artisan::call('cards:unpair', ['--force' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('Unpairing EVERY card', $out);
        $this->assertStringContainsString('Desvinculando TODAS las tarjetas', $out);
        $this->assertStringContainsString("{$cards} card(s)", $out);
        $this->assertStringContainsString("{$events} event(s)", $out);
        $this->assertStringContainsString('card(s) unpaired', $out);
        $this->assertStringContainsString('tarjeta(s) desvinculada(s)', $out);
        $this->assertStringContainsString('./run reset', $out);
    }

    #[Test]
    public function unpairing_an_empty_cards_table_is_a_clean_noop(): void
    {
        // No seedDemo: zero cards in the database.
        Artisan::call('cards:unpair', ['--force' => true]);
        $this->assertStringContainsString('Nothing to unpair', Artisan::output());
        $this->assertSame(0, Card::count());

        // The guarded path also stays a noop (no prompt-driven crash).
        $this->artisan('cards:unpair')->assertSuccessful();
        $this->assertSame(0, Card::count());
    }

    #[Test]
    public function a_not_ready_database_fails_fast_with_remediation_instead_of_a_traceback(): void
    {
        // A sqlite file that exists but was never migrated (setup
        // interrupted mid-way): the counts run before ANY mutation, so
        // the command must fail fast with the bilingual remediation,
        // not a QueryException traceback.
        Schema::drop('cards');

        $this->artisan('cards:unpair', ['--force' => true])
            ->assertExitCode(1)
            ->expectsOutput('Database not ready (cards table unreachable) — run: ./run setup or ./run reset')
            ->expectsOutput('Base de datos no lista (tabla cards inaccesible) — ejecuta: ./run setup o ./run reset');
    }
}

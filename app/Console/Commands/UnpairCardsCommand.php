<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-013 — unpair every card (dev/testing utility, ADR-023).
 *
 * A card is "fresh" (pairable) exactly when it has NO cards row: the
 * pairing flow rejects any credential_uid that already exists, whatever
 * its status (PairingService::pair — invariant 2 of the card-pairing
 * flow, ADR-020). So "unpair" cannot mean clearing student_id — the row
 * itself is what blocks re-pairing. This command deletes every cards row,
 * which restores every physical credential to fresh, pair-any-student
 * state: exactly what repeated bench testing of the arm-then-pair flow
 * needs after a successful pair consumed the card.
 *
 * Deliberate semantics (mirroring the FK contract of the schema):
 * - tap events of those cards are deleted (events.card_id is
 *   cascadeOnDelete);
 * - pending_pairings.card_id links are cleared, history rows survive
 *   (nullOnDelete audit trail — TASK-011);
 * - students, readers, users, points and recycling data are untouched.
 * Deletes run explicitly inside ONE transaction so the outcome is
 * deterministic even where the sqlite foreign_key pragma is off.
 */
class UnpairCardsCommand extends Command
{
    protected $signature = 'cards:unpair
        {--force : Skip the confirmation prompt / Saltar la pregunta de confirmación}';

    protected $description = 'Unpair every card (testing reset): deletes all cards + their tap events so every credential is fresh again / Desvincula todas las tarjetas (reset de pruebas): borra todas las tarjetas y sus eventos para que cada credencial vuelva a estar fresca';

    public function handle(): int
    {
        $cardCount = Card::count();
        $eventCount = PresenceEvent::count(); // every event belongs to a card (FK), so all of them go
        $linkCount = PendingPairing::whereNotNull('card_id')->count();

        if ($cardCount === 0) {
            $this->info('Nothing to unpair — 0 cards. / Nada que desvincular — 0 tarjetas.');

            return self::SUCCESS;
        }

        $summary = "{$cardCount} card(s) / tarjeta(s), {$eventCount} event(s) / evento(s), {$linkCount} history link(s) / enlace(s) de historial";
        $this->warn("Unpairing EVERY card — will delete: {$summary}. Students, readers and pairing history rows survive.");
        $this->warn("Desvinculando TODAS las tarjetas — se borrará: {$summary}. Estudiantes, lectores e historial de emparejamiento sobreviven.");

        if (! $this->option('force') && ! $this->confirm('Proceed? / ¿Continuar?')) {
            $this->info('Aborted — nothing changed. / Cancelado — no cambió nada.');

            return self::SUCCESS;
        }

        [$cardsDeleted, $eventsDeleted, $linksCleared] = DB::transaction(function (): array {
            // Same order a DB-level cascade would apply, but explicit and
            // counted: children first, then the cards themselves.
            $eventsDeleted = PresenceEvent::query()->delete();
            $linksCleared = PendingPairing::whereNotNull('card_id')->update(['card_id' => null]);
            $cardsDeleted = Card::query()->delete();

            return [$cardsDeleted, $eventsDeleted, $linksCleared];
        });

        $this->info("[OK] {$cardsDeleted} card(s) unpaired, {$eventsDeleted} event(s) deleted, {$linksCleared} history link(s) cleared — every credential is fresh again: arm a pairing and tap any card.");
        $this->info("[OK] {$cardsDeleted} tarjeta(s) desvinculada(s), {$eventsDeleted} evento(s) borrado(s), {$linksCleared} enlace(s) de historial limpiado(s) — cada credencial está fresca otra vez: arma un emparejamiento y toca cualquier tarjeta.");
        $this->line('Tip / Consejo: ./run reset restores the seeded demo cards. / ./run reset restaura las tarjetas demo.');

        return self::SUCCESS;
    }
}

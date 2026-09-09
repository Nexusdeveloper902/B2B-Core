<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\PendingPairing;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
 * - students, readers, users and pairing history are untouched;
 * - points LEDGER rows survive (points_ledger.event_id is nullOnDelete),
 *   so balances keep their value;
 * - recycling DEPOSITS DO NOT survive: recycling_deposits.event_id is a
 *   unique cascadeOnDelete FK to events, so every deposit row (and its
 *   stored capture image file) is destroyed with the events. This was
 *   previously mis-documented as "recycling untouched" — the audit fixed
 *   the copy, not the semantics (the schema contract is the intent).
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
        // The counts read the schema, so a database that exists but was
        // never migrated (setup interrupted, hand-touched sqlite file)
        // must fail FAST with remediation, not a QueryException
        // traceback — the counts run BEFORE any mutation, so nothing
        // has been touched when this fires.
        try {
            $cardCount = Card::count();
            $eventCount = PresenceEvent::count(); // every event belongs to a card (FK), so all of them go
            $linkCount = PendingPairing::whereNotNull('card_id')->count();
            $depositCount = RecyclingDeposit::count(); // deposits cascade with events (unique event FK)
        } catch (QueryException) {
            $this->error('Database not ready (cards table unreachable) — run: ./run setup or ./run reset');
            $this->error('Base de datos no lista (tabla cards inaccesible) — ejecuta: ./run setup o ./run reset');

            return self::FAILURE;
        }

        if ($cardCount === 0) {
            $this->info('Nothing to unpair — 0 cards. / Nada que desvincular — 0 tarjetas.');

            return self::SUCCESS;
        }

        $summary = "{$cardCount} card(s) / tarjeta(s), {$eventCount} event(s) / evento(s), {$depositCount} recycling deposit(s) / recarga(s) de reciclaje, {$linkCount} history link(s) / enlace(s) de historial";
        $this->warn("Unpairing EVERY card — will delete: {$summary}. Point balances survive; deposit images do not.");
        $this->warn("Desvinculando TODAS las tarjetas — se borrará: {$summary}. Los saldos de puntos sobreviven; las imágenes de depósitos no.");

        if (! $this->option('force') && ! $this->confirm('Proceed? / ¿Continuar?')) {
            $this->info('Aborted — nothing changed. / Cancelado — no cambió nada.');

            return self::SUCCESS;
        }

        [$cardsDeleted, $eventsDeleted, $depositsDeleted, $linksCleared, $imagePaths] = DB::transaction(function (): array {
            // Same order a DB-level cascade would apply, but explicit and
            // counted: children first, then the cards themselves. Deposit
            // image paths are collected BEFORE the cascade deletes the
            // rows — the files must not orphan on disk.
            $imagePaths = RecyclingDeposit::query()
                ->whereNotNull('image_path')
                ->pluck('image_path')
                ->all();
            $depositsDeleted = RecyclingDeposit::query()->delete();
            $eventsDeleted = PresenceEvent::query()->delete();
            $linksCleared = PendingPairing::whereNotNull('card_id')->update(['card_id' => null]);
            $cardsDeleted = Card::query()->delete();

            return [$cardsDeleted, $eventsDeleted, $depositsDeleted, $linksCleared, $imagePaths];
        });

        // The deposit rows are gone; their stored capture images follow
        // (the private audit trail dies with the deposits it belongs to).
        $imagesDeleted = 0;
        foreach ($imagePaths as $path) {
            if (Storage::disk('local')->delete($path)) {
                $imagesDeleted++;
            }
        }

        $this->info("[OK] {$cardsDeleted} card(s) unpaired, {$eventsDeleted} event(s) deleted, {$depositsDeleted} deposit(s) deleted, {$imagesDeleted} image(s) removed, {$linksCleared} history link(s) cleared — every credential is fresh again: arm a pairing and tap any card.");
        $this->info("[OK] {$cardsDeleted} tarjeta(s) desvinculada(s), {$eventsDeleted} evento(s) borrado(s), {$depositsDeleted} depósito(s) borrado(s), {$imagesDeleted} imagen(es) eliminada(s), {$linksCleared} enlace(s) de historial limpiado(s) — cada credencial está fresca otra vez: arma un emparejamiento y toca cualquier tarjeta.");
        $this->line('Tip / Consejo: ./run reset restores the seeded demo cards. / ./run reset restaura las tarjetas demo.');

        return self::SUCCESS;
    }
}

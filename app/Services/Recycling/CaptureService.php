<?php

namespace App\Services\Recycling;

use App\Enums\PendingCaptureState;
use App\Models\Card;
use App\Models\PendingCapture;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use App\Services\Realtime\RecyclingUpdateLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-025 item 2 — the bottle-first flow (spec §3 Case B, §5, §32).
 *
 * Case B (bottle placed BEFORE any card): the station captures an image
 * with no student attached. Cost gate (spec §4): NO classifier call
 * happens at capture time — an image can never reach DeepSeek before a
 * student is associated, in EITHER flow (card-first by construction,
 * bottle-first by this service's ordering).
 *
 * State machine (durable half; see PendingCaptureState):
 *   store()     : image persisted, row awaiting_card, ttl countdown
 *   associate() : card validated -> event created -> validating ->
 *                 classifyAndAward() (single award path, atomic) ->
 *                 accepted
 *   expiry      : sweep() flips awaiting_card rows past expires_at to
 *                 expired — no award, no leak (proven by test)
 *
 * The same sweep repairs `validating` rows left behind by a crash
 * between the association transaction and the award transaction: a
 * validating capture whose event already has a deposit becomes
 * accepted; one without becomes failed.
 */
class CaptureService
{
    public function __construct(
        private readonly ClassificationService $classification,
    ) {}

    /**
     * Case B start: persist the captured image and open the
     * awaiting-card window.
     *
     * @return array{ok: true, capture: PendingCapture, expires_in: int}
     */
    public function store(Reader $reader, UploadedFile $image): array
    {
        $this->sweep();

        $ttl = max(10, (int) config('recycling.capture.ttl_seconds'));

        $storedPath = $image->store('recycling-captures', 'local');

        $capture = DB::transaction(function () use ($reader, $storedPath, $ttl) {
            $capture = PendingCapture::create([
                'reader_id' => $reader->id,
                'image_path' => $storedPath,
                'state' => PendingCaptureState::AwaitingCard->value,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            RecyclingUpdateLog::record(RecyclingUpdate::TYPE_CAPTURE_CREATED, [
                'capture_id' => $capture->id,
                'reader_id' => $reader->id,
                'reader_label' => $reader->label,
                'state' => PendingCaptureState::AwaitingCard->value,
                'expires_in' => $ttl,
            ]);

            return $capture;
        });

        return ['ok' => true, 'capture' => $capture, 'expires_in' => $ttl];
    }

    /**
     * Case B resolution: a card tap (sent as credential_uid) resolves
     * the station's capture — event created, image classified, points
     * awarded, capture accepted. Card-level rejections leave the
     * capture usable (the window keeps waiting for the right card);
     * ownership and expiry rejections are terminal.
     *
     * @return array{ok: true, capture: PendingCapture, result: array{duplicate: bool, deposit: RecyclingDeposit, new_balance: int}}
     *                                                                                                                               |array{ok: false, reason: string, message: string, state?: string}
     */
    public function associate(Reader $reader, int $captureId, string $credentialUid): array
    {
        $this->sweep();

        $capture = PendingCapture::find($captureId);

        if ($capture === null) {
            return ['ok' => false, 'reason' => 'no_pending_capture', 'message' => __('api.no_pending_capture')];
        }

        if ((int) $capture->reader_id !== (int) $reader->id) {
            return ['ok' => false, 'reason' => 'not_owned', 'message' => __('api.capture_not_owned_by_reader')];
        }

        if (! $reader->isRecycling()) {
            return ['ok' => false, 'reason' => 'not_recycling_reader', 'message' => __('api.event_not_recycling')];
        }

        $card = Card::where('credential_uid', $credentialUid)->first();

        if ($card === null) {
            // Card-level rejection is NOT terminal for the capture: the
            // window keeps waiting for the right card (spec §32).
            return ['ok' => false, 'reason' => 'card_not_found', 'message' => __('api.card_not_recognized')];
        }

        if (! $card->isActive()) {
            return ['ok' => false, 'reason' => 'card_inactive', 'message' => __('api.card_not_active')];
        }

        if (! $capture->isUsable()) {
            return [
                'ok' => false,
                'reason' => 'no_pending_capture',
                'message' => __('api.no_pending_capture'),
                'state' => $capture->state->value,
            ];
        }

        $student = $card->student;

        // Transaction A: claim the capture for this card + create the tap
        // event + broadcast validation_started. A concurrent associator
        // on the same capture loses the state guard (lockForUpdate +
        // usable() re-check) and gets already_associated, never a race.
        try {
            $event = DB::transaction(function () use ($capture, $reader, $card, $student) {
                $locked = PendingCapture::whereKey($capture->id)->lockForUpdate()->first();

                if ($locked === null || $locked->state !== PendingCaptureState::AwaitingCard || ! $locked->isUsable()) {
                    throw new CaptureAlreadyAssociated;
                }

                $event = PresenceEvent::create([
                    'card_id' => $card->id,
                    'reader_id' => $reader->id,
                    'type' => $reader->active_event_type,
                    'occurred_at' => now(),
                    'metadata' => ['capture_id' => $locked->id],
                ]);

                $locked->update([
                    'event_id' => $event->id,
                    'card_id' => $card->id,
                    'state' => PendingCaptureState::Validating->value,
                ]);

                RecyclingUpdateLog::record(RecyclingUpdate::TYPE_VALIDATION_STARTED, [
                    'capture_id' => $locked->id,
                    'event_id' => $event->id,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'state' => PendingCaptureState::Validating->value,
                ]);

                return $event;
            });
        } catch (CaptureAlreadyAssociated) {
            return [
                'ok' => false,
                'reason' => 'already_associated',
                'message' => __('api.capture_already_associated'),
                'state' => $capture->refresh()->state->value,
            ];
        }

        // The single award path — atomic deposit+ledger, frames inside.
        // The capture flip rides the SAME transaction via the callback.
        $result = $this->classification->classifyAndAward(
            $reader,
            $event,
            (string) Storage::disk('local')->path((string) $capture->image_path),
            $capture->image_path,
            function () use ($capture) {
                PendingCapture::whereKey($capture->id)->update([
                    'state' => PendingCaptureState::Accepted->value,
                ]);
            },
        );

        return ['ok' => true, 'capture' => $capture->refresh(), 'result' => $result];
    }

    /**
     * Lazy expiry + repair sweep. Mutations happen on the API write
     * paths (store/associate prologues), never inside the WS poll —
     * the socket server stays read-only by design.
     */
    public function sweep(): void
    {
        // awaiting_card past expiry -> expired (no award, no leak).
        PendingCapture::query()
            ->where('state', PendingCaptureState::AwaitingCard->value)
            ->where('expires_at', '<=', now())
            ->update(['state' => PendingCaptureState::Expired->value]);

        // validating + the event already has a deposit (crash between
        // transaction A and the award transaction) -> accepted.
        PendingCapture::query()
            ->where('state', PendingCaptureState::Validating->value)
            ->whereHas('event.deposit')
            ->update(['state' => PendingCaptureState::Accepted->value]);

        // validating without a deposit for longer than the award could
        // plausibly take -> failed (a human may re-capture; no points moved).
        $grace = 2 * max(10, (int) config('recycling.capture.ttl_seconds'));
        PendingCapture::query()
            ->where('state', PendingCaptureState::Validating->value)
            ->whereDoesntHave('event.deposit')
            ->where('updated_at', '<=', now()->subSeconds($grace))
            ->update(['state' => PendingCaptureState::Failed->value]);
    }
}

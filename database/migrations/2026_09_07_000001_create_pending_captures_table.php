<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-025 item 2 — the bottle-first flow (spec §3 Case B, §5, §32).
     *
     * A captured image that arrives BEFORE any card tap (bottle placed on
     * the station, trigger pressed) is held here in state `awaiting_card`
     * until a card association resolves it. Mirrors the proven
     * pending_pairings shape: transient rows, expiry, clock-jump guard
     * (see PendingCapture::isActive).
     *
     * The spec's full station state machine (IDLE → CARD_IDENTIFIED → …)
     * is the DEVICE-side lifecycle; this table persists only the states
     * the backend must survive restarts with:
     * awaiting_card → validating → accepted | rejected | failed | expired.
     */
    public function up(): void
    {
        Schema::create('pending_captures', function (Blueprint $table) {
            $table->id();
            // The camera-station reader that captured the image (also the
            // reader a later association must arrive on — 403 otherwise).
            $table->foreignId('reader_id')->constrained('readers')->cascadeOnDelete();
            // Stored image reference (Storage disk 'local', 'recycling-captures/').
            $table->string('image_path');
            // Stamped at association: the tap event created for the flow.
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            // Stamped at association: the card that resolved the capture.
            $table->foreignId('card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->string('state')->default('awaiting_card');
            $table->timestamp('expires_at');
            $table->timestamps();

            // The association lookup: newest unconsumed row per station +
            // the expiry sweep.
            $table->index(['reader_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_captures');
    }
};

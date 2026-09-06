<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-025 item 6 — recycling WS frames (spec §24–§29).
     *
     * Append-only broadcast source for the realtime feed, exactly like
     * the `events` table is for tap frames: rows are written INSIDE the
     * same DB transaction as the state change they describe (capture
     * stored, validation started, deposit validated, points awarded,
     * reward redeemed, leaderboard updated), so nothing ever broadcasts
     * pre-commit — realtime:serve only ever polls committed rows.
     *
     * Payloads are precomputed by the writer (the moment knows the
     * context); the socket server never joins or recomputes.
     */
    public function up(): void
    {
        Schema::create('recycling_updates', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // capture_created|validation_started|validated|points_awarded|reward_redeemed|leaderboard_updated
            $table->text('payload'); // JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recycling_updates');
    }
};

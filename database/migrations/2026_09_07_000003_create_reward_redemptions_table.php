<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-025 item 7 — the rewards model (spec §19/§20).
     *
     * A redemption today persists only as a points_ledger row with
     * reward_id — no record of WHICH redemption, no stock accounting.
     * This table makes every redemption a first-class row:
     *
     *   - one row per successful spend (ledger_id unique → structural
     *     1:1 with the ledger movement, NOT the double-submit guard)
     *   - the (student, reward, created_at) index serves the duplicate
     *     window query (double-submit protection)
     *   - counting rows per reward is the audit trail for stock
     */
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_id')->constrained('rewards')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('ledger_id')->unique()->constrained('points_ledger')->cascadeOnDelete();
            $table->integer('points_spent');
            // Double-submit protection (spec §20): a client-supplied
            // idempotency key. UNIQUE: a repeated request_id replays the
            // ORIGINAL redemption answer instead of charging again.
            // Nullable: clients that send none fall back to the
            // time-window heuristic in PointsService.
            $table->string('request_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['student_id', 'reward_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-014 — rejection visibility on the pairing desk: when a pair
     * tap is REJECTED during an armed window (422 already_paired — the
     * credential is burned by ADR-020 invariant 2), the device gets the
     * JSON answer but the desk was blind: it kept counting down and then
     * reported "window expired", so the operator never learned WHY
     * pairing "stopped working". These columns stamp the latest rejected
     * tap on the armed row so the status feed (and the desk) can show it
     * while the window is live. Nullable, transient like the window
     * itself: once the row is consumed or expired, pending no longer
     * reports it.
     */
    public function up(): void
    {
        Schema::table('pending_pairings', function (Blueprint $table) {
            $table->string('last_rejected_uid')->nullable()->after('card_id');
            $table->string('last_rejected_reason')->nullable()->after('last_rejected_uid');
            $table->timestamp('last_rejected_at')->nullable()->after('last_rejected_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_pairings', function (Blueprint $table) {
            $table->dropColumn(['last_rejected_uid', 'last_rejected_reason', 'last_rejected_at']);
        });
    }
};

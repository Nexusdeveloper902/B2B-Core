<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-029 — the roster WS channel (the "everything that could
     * change needs websockets" passover).
     *
     * Append-only broadcast source for roster changes (student created,
     * students imported, class created, reader updated), exactly like
     * `recycling_updates` is for the recycling channel: rows are
     * written INSIDE the same DB transaction as the state change they
     * describe, so realtime:serve can never broadcast pre-commit state.
     *
     * Payloads are precomputed by the writer; the socket server never
     * joins or recomputes. Frames are delivered to ADMIN connections
     * only (roster management is an admin surface, and payloads carry
     * the same class/PAE data the admin REST endpoints serve).
     */
    public function up(): void
    {
        Schema::create('roster_updates', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // student_created|students_imported|class_created|reader_updated
            $table->text('payload'); // JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_updates');
    }
};

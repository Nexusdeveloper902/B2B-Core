<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-047 — the realtime log for taps that write NO events row:
     * a same-day duplicate (first tap counts, TASK-043) or an
     * unknown/inactive card. The device still got an answer, so a
     * feedback device (the Android speaker bridge) must still hear
     * about it. Same append-only broadcast pattern as roster_updates /
     * recycling_updates; realtime:serve pushes rows as `feedback`
     * frames. Taps that DO write a row keep riding the `tap` frame.
     */
    public function up(): void
    {
        Schema::create('tap_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->index();
            $table->foreignId('reader_id')->nullable();
            $table->foreignId('event_id')->nullable(); // the original row, for duplicates
            $table->string('cue', 16); // accepted|rejected
            $table->string('reason', 32); // duplicate|not_found|inactive
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tap_feedback');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-010 — card pairing: the "pending pairing" that links a desk-side
     * arming action to the next fresh card the reader scans. Transient by
     * design: rows expire (expires_at) or are consumed (consumed_at) within
     * seconds of their creation in normal use.
     */
    public function up(): void
    {
        Schema::create('pending_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // Which reader consumed the pairing (stamped on success); null
            // until then. Nullable: the arming side does not know the reader.
            $table->foreignId('reader_id')->nullable()->constrained('readers')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // The pair lookup: most recent unconsumed, unexpired row.
            $table->index(['consumed_at', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_pairings');
    }
};

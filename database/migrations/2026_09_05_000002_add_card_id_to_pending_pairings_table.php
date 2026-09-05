<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-011 — card_id on pending_pairings: stamped at consumption so a
     * completed pairing points at the exact cards row it created (audit
     * trail for the dashboard pairing desk). Nullable FK, like reader_id:
     * historical rows (pre-TASK-011) simply stay null.
     */
    public function up(): void
    {
        Schema::table('pending_pairings', function (Blueprint $table) {
            $table->foreignId('card_id')
                ->nullable()
                ->after('reader_id')
                ->constrained('cards')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_pairings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-037 — DB-backed runtime settings (ADR-055).
     *
     * Administrators configure safe presence/PAE behavior through the
     * /admin/settings UI instead of editing .env: meal serving windows,
     * the attendance late cutoff, the card-pairing window, and student
     * account conventions. Rows are a `key` → JSON `value` override; the
     * config/presence.php defaults (env-overridable) remain the fallback
     * chain: DB row → config default → hardcoded default.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

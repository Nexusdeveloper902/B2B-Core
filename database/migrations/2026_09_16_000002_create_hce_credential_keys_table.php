<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-049 (ADR-068) — per-credential HCE keys.
     *
     * One row per phone credential (`cards.kind = hce`): the 256-bit
     * HMAC key that phone holds in its Android Keystore, stored with
     * Laravel's `encrypted` cast (APP_KEY), never in plaintext. The
     * backend is now the HCE verifier; readers carry no HCE secret.
     *
     * Revocation deletes the row (the card keeps status `revoked`), so a
     * revoked credential cannot authenticate even if its status were
     * flipped back by hand. Unpairing cascades the row away with the card.
     */
    public function up(): void
    {
        Schema::create('hce_credential_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->unique()->constrained('cards')->cascadeOnDelete();
            $table->text('secret'); // encrypted cast — ciphertext only
            // sha256(key)[0:16]: public, for audit/support ("which key is this?").
            $table->string('fingerprint', 16);
            $table->foreignId('provisioned_by_reader_id')->nullable()->constrained('readers')->nullOnDelete();
            $table->timestamp('provisioned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hce_credential_keys');
    }
};

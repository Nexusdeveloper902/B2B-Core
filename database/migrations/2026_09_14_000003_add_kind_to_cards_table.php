<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HCE credential integration — distinguish HOW a credential was
     * captured: a physical MIFARE UID read off the RF layer vs an
     * application-level Android HCE credential id obtained through the
     * SELECT AID + CHALLENGE APDU exchange (AID F0010203040506).
     *
     * The column is display/audit metadata only: tap lookup stays
     * credential_uid-only (the RF UID is never an identity — the HCE
     * phone randomizes it per tap), existing rows backfill as
     * 'physical', and pairing defaults to 'physical' when the reader
     * omits the kind (old firmware keeps working byte-for-byte).
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('kind')->default('physical')->after('credential_uid'); // physical | hce
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};

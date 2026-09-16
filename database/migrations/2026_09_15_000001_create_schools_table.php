<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-045 (ADR-064) — the school (organization) spine.
     *
     * Pulse had no tenant concept: every row belonged to "the school"
     * implicitly. This table makes that owner explicit so one Core
     * install can host more than one institution, and so a school can
     * carry its own visual identity.
     *
     * Columns:
     *   name       the institution's own name, as it prints on screen
     *              (e.g. "IE Concejo de Sabaneta J.M.C.B")
     *   slug       the stable machine identifier (URLs, fixtures, tests)
     *   brand_key  which branding profile in config/branding.php this
     *              school renders with. NULL (or an unknown key) falls
     *              back to the default Pulse identity — a school is
     *              never REQUIRED to be branded.
     *
     * Deliberately NOT here: colors, logo paths, fonts. Branding values
     * are configuration (versioned with the asset that ships them), not
     * per-row data an operator can drift into an unreadable contrast
     * pair. The row only NAMES its profile.
     */
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('brand_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};

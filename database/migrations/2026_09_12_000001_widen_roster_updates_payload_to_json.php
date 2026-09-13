<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-049 (SQLite → MariaDB) — roster payload widened to JSON.
     *
     * `roster_updates.payload` was `text` (65,535 bytes on MariaDB vs
     * effectively unbounded on SQLite). A bulk CSV import broadcast —
     * the exact moment the students_imported frame is written — carries
     * the imported roster in one payload and blew past the TEXT ceiling
     * in strict mode (SQLSTATE 22001, HTTP 500 mid-import). The column
     * is a commit-only broadcast log; it must never be the size limit
     * of an import. `json` = LONGTEXT on MariaDB, TEXT on SQLite.
     */
    public function up(): void
    {
        Schema::table('roster_updates', function (Blueprint $table) {
            $table->json('payload')->change();
        });
    }

    public function down(): void
    {
        Schema::table('roster_updates', function (Blueprint $table) {
            $table->text('payload')->change();
        });
    }
};

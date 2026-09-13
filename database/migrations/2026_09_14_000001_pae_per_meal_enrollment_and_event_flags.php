<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-037 — the per-meal PAE enrollment model + the served/flagged
     * event semantics.
     *
     * 1. students: the single `pae_enrolled` flag becomes two independent
     *    enrollment flags — breakfast and lunch. A student may be enrolled
     *    in breakfast only, lunch only, both, or neither. The old flag is
     *    copied into both (a superset, so no enrolled student loses a meal
     *    across the upgrade); the spec allows a fresh reseed instead, but
     *    the copy is one statement and keeps existing dev databases honest.
     *
     * 2. events: two columns making the valid-served vs flagged-excluded
     *    distinction EXPLICIT on the event-type spine (see ADR-053):
     *    - `served` (default true): only rows with served=true contribute
     *      to PAE served-meal statistics. A rejected PAE attempt is still
     *      an events row (auditable, visible in feeds) with served=false.
     *    - `reason` (nullable): the machine-stable rejection reason for
     *      flagged rows (not_enrolled / no_attendance / out_of_window /
     *      weekend / duplicate / window_overlap / no_student).
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('pae_breakfast_enrolled')->default(false)->after('grade');
            $table->boolean('pae_lunch_enrolled')->default(false)->after('pae_breakfast_enrolled');
        });

        // Superset copy: previously-enrolled students keep both meals.
        if (Schema::hasColumn('students', 'pae_enrolled')) {
            DB::table('students')->where('pae_enrolled', true)->update([
                'pae_breakfast_enrolled' => true,
                'pae_lunch_enrolled' => true,
            ]);

            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn('pae_enrolled');
            });
        }

        Schema::table('events', function (Blueprint $table) {
            $table->boolean('served')->default(true)->after('type');
            $table->string('reason')->nullable()->after('served');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['reason', 'served']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->boolean('pae_enrolled')->default(false)->after('grade');
        });

        // Best-effort inverse of the superset copy (union of both flags).
        DB::table('students')
            ->where('pae_breakfast_enrolled', true)
            ->orWhere('pae_lunch_enrolled', true)
            ->update(['pae_enrolled' => true]);

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['pae_breakfast_enrolled', 'pae_lunch_enrolled']);
        });
    }
};

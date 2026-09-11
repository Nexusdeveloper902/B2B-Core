<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-030 follow-up (adversarial finding 3.2) — the "same name in
     * the same class is a duplicate" rule lived only as an application
     * pre-check, so two concurrent creates could both pass exists() and
     * commit twin rows (each minting its own login). The database owns
     * the invariant now; the controllers translate the violation back
     * into the honest 422 duplicate / per-row import error.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unique(['name', 'class_id'], 'students_name_class_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_name_class_unique');
        });
    }
};

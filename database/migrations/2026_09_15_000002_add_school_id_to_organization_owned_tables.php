<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-045 (ADR-064) — organization ownership on the ROOT
     * org-owned tables.
     *
     * Only the roots carry the column. Everything else in the schema
     * descends from one of them (cards -> students, points_ledger ->
     * students, recycling_deposits -> events, reward_redemptions ->
     * students, pending_pairings -> students, pending_captures ->
     * readers) and is scoped through that parent, so the ownership
     * fact is stored exactly once and can never disagree with itself.
     *
     * `events` is the one denormalization: the dashboards aggregate it
     * directly and constantly (attendance/PAE/recycling are all derived
     * views over this table), so a join-per-aggregate would be paid on
     * every render. TapService/MealServingService stamp it from the
     * READER — the device's own identity, never a client-supplied id.
     *
     * The two realtime log tables (roster_updates, recycling_updates)
     * carry it so the WebSocket server can filter frames per connection
     * without parsing payload JSON.
     *
     * ALL columns are nullable, and NULL is a first-class value: it
     * means "not assigned to any organization". Every pre-existing row
     * keeps working exactly as before (see ADR-064's fallback rule —
     * a NULL-school admin is the system administrator), so this
     * migration is additive and reversible with no data rewrite.
     */
    public function up(): void
    {
        foreach (['users', 'classes', 'students', 'readers', 'rewards', 'events', 'roster_updates', 'recycling_updates'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('school_id')
                    ->nullable()
                    ->constrained('schools')
                    ->nullOnDelete();
            });
        }

        // The hot read paths: "this school's events of this day/type"
        // and "this school's roster", which every dashboard runs.
        Schema::table('events', function (Blueprint $table) {
            $table->index(['school_id', 'type', 'occurred_at'], 'events_school_type_occurred_index');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->index(['school_id', 'class_id'], 'students_school_class_index');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_school_class_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_school_type_occurred_index');
        });

        foreach (['recycling_updates', 'roster_updates', 'events', 'rewards', 'readers', 'students', 'classes', 'users'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('school_id');
            });
        }
    }
};

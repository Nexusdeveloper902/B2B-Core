<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-025 items 5 + 7 (spec §11/§12/§30 accounts; §19/§20 catalog).
     *
     * users.student_id — the 1:1 student account layer: a student USER
     * row that REFERENCES the existing students row (never a second
     * student identity). Unique: one account per student. Nullable FK:
     * deleting the student keeps the account row unlinked rather than
     * cascading into users.
     *
     * rewards catalog columns:
     *   - type        (voucher | raffle | privilege | shoutout … informational)
     *   - value       (optional numeric value — e.g. cents of discount)
     *   - active      (soft catalog switch; inactive rewards are unredeemable)
     *   - stock       (NULL = unlimited; otherwise remaining units, atomically
     *                  decremented on redemption — the over-redemption guard)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('student_id')
                ->nullable()
                ->after('role')
                ->unique()
                ->constrained('students')
                ->nullOnDelete();
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->string('type')->default('voucher')->after('name');
            $table->integer('value')->nullable()->after('point_cost');
            $table->boolean('active')->default(true)->after('description');
            $table->integer('stock')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropColumn(['type', 'value', 'active', 'stock']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
        });
    }
};

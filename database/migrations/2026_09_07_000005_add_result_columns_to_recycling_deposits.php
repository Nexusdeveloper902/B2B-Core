<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-025 items 3 + 8 (spec §14 image persistence; §9 AI result
     * schema).
     *
     * recycling_deposits gains:
     *   - image_path    — the stored capture (audit trail; the classify
     *                     contract persists the exact image that was judged)
     *   - is_bottle     — bottle-shape semantics from the classifier
     *   - is_recyclable — recyclability semantics from the classifier
     *
     * Points still come ONLY from material_class via config (backend owns
     * all business rules — spec §10); the two booleans are persisted
     * boundary knowledge available to future rules, NL queries, audits.
     */
    public function up(): void
    {
        Schema::table('recycling_deposits', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('event_id');
            $table->boolean('is_bottle')->default(false)->after('points_awarded');
            $table->boolean('is_recyclable')->default(false)->after('is_bottle');
        });
    }

    public function down(): void
    {
        Schema::table('recycling_deposits', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'is_bottle', 'is_recyclable']);
        });
    }
};

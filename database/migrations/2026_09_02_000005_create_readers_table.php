<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('readers', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('type'); // classroom | pae | recycling | entry
            $table->string('active_event_type'); // e.g. CLASS_ATTENDANCE, PAE_BREAKFAST, RECYCLING_DEPOSIT, ENTRY
            $table->string('api_key')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readers');
    }
};

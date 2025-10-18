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
        Schema::create('sport_time_based_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "Peak Hours", "Off-Peak", "Weekend Premium"
            $table->time('start_time'); // e.g., 06:00
            $table->time('end_time'); // e.g., 10:00
            $table->decimal('price_per_hour', 10, 2);
            $table->json('days_of_week')->nullable(); // [1,2,3,4,5] for Mon-Fri, null for all days
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // Higher priority rules override lower ones
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sport_time_based_pricing');
    }
};

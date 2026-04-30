<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_sport_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained('holidays')->onDelete('cascade');
            $table->foreignId('sport_id')->constrained('sports')->onDelete('cascade');
            $table->decimal('price_per_hour', 10, 2);
            $table->timestamps();

            $table->unique(['holiday_id', 'sport_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_sport_pricing');
    }
};

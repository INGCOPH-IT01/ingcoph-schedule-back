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
        Schema::create('sport_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_id')->constrained()->onDelete('cascade');
            $table->string('change_type'); // 'default_price_updated', 'time_based_pricing_created', 'time_based_pricing_updated', 'time_based_pricing_deleted'
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null'); // Who made the change
            $table->json('old_value')->nullable(); // Old pricing details
            $table->json('new_value')->nullable(); // New pricing details
            $table->dateTime('effective_date')->nullable(); // When the change takes/took effect
            $table->text('description')->nullable(); // Human-readable description of the change
            $table->timestamps();

            // Index for efficient querying
            $table->index(['sport_id', 'created_at']);
            $table->index('change_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sport_price_histories');
    }
};

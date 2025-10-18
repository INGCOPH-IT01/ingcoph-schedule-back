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
        // Add sport_id to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('sport_id')->nullable()->after('court_id')->constrained()->onDelete('cascade');
            $table->index('sport_id');
        });

        // Add sport_id to cart_items table
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('sport_id')->nullable()->after('court_id')->constrained()->onDelete('cascade');
            $table->index('sport_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['sport_id']);
            $table->dropIndex(['sport_id']);
            $table->dropColumn('sport_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['sport_id']);
            $table->dropIndex(['sport_id']);
            $table->dropColumn('sport_id');
        });
    }
};

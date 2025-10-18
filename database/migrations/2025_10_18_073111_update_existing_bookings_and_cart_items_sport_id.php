<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing bookings that don't have a sport_id
        // Set sport_id to their court's sport_id
        DB::statement('
            UPDATE bookings
            INNER JOIN courts ON bookings.court_id = courts.id
            SET bookings.sport_id = courts.sport_id
            WHERE bookings.sport_id IS NULL AND courts.sport_id IS NOT NULL
        ');

        // Update existing cart_items that don't have a sport_id
        // Set sport_id to their court's sport_id
        DB::statement('
            UPDATE cart_items
            INNER JOIN courts ON cart_items.court_id = courts.id
            SET cart_items.sport_id = courts.sport_id
            WHERE cart_items.sport_id IS NULL AND courts.sport_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse this data migration
        // sport_id can remain populated even if rolled back
    }
};

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
        // Populate the court_sport pivot table with existing court-sport relationships
        $courts = \DB::table('courts')->whereNotNull('sport_id')->get();

        foreach ($courts as $court) {
            \DB::table('court_sport')->insert([
                'court_id' => $court->id,
                'sport_id' => $court->sport_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Clear the court_sport pivot table
        \DB::table('court_sport')->truncate();
    }
};

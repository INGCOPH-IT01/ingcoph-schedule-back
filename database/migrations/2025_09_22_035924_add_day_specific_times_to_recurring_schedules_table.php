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
        Schema::table('recurring_schedules', function (Blueprint $table) {
            // Add day-specific times as JSON
            $table->json('day_specific_times')->nullable()->after('recurrence_days');
            // Example structure: 
            // [
            //   {"day": 0, "start_time": "08:00", "end_time": "10:00"}, // Sunday
            //   {"day": 2, "start_time": "14:00", "end_time": "16:00"}, // Tuesday  
            //   {"day": 5, "start_time": "18:00", "end_time": "20:00"}  // Friday
            // ]
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_schedules', function (Blueprint $table) {
            $table->dropColumn('day_specific_times');
        });
    }
};

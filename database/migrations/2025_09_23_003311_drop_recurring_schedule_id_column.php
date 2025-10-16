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
        // Only drop the column if it exists
        if (Schema::hasColumn('bookings', 'recurring_schedule_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('recurring_schedule_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('recurring_schedule_id')->nullable()->constrained('recurring_schedules')->onDelete('set null');
        });
    }
};

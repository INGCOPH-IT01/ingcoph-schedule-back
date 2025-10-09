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
            $table->enum('recurrence_type', ['daily', 'weekly', 'weekly_multiple_times', 'monthly', 'yearly', 'yearly_multiple_times'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_schedules', function (Blueprint $table) {
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])->change();
        });
    }
};

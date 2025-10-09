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
        Schema::table('bookings', function (Blueprint $table) {
            // Add frequency fields
            $table->enum('frequency_type', ['once', 'daily', 'weekly', 'monthly', 'yearly'])->default('once')->after('status');
            $table->json('frequency_days')->nullable()->after('frequency_type'); // For weekly/monthly: [1,3,5] = Mon,Wed,Fri
            $table->json('frequency_times')->nullable()->after('frequency_days'); // For multiple times per day: [{"start":"08:00","end":"10:00"},{"start":"14:00","end":"16:00"}]
            $table->integer('frequency_duration_months')->nullable()->after('frequency_times'); // How long the frequency lasts (e.g., 12 months)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['frequency_type', 'frequency_days', 'frequency_times', 'frequency_duration_months']);
        });
    }
};
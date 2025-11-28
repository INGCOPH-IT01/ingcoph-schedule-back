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
        // Insert default reschedule window setting
        DB::table('company_settings')->updateOrInsert(
            ['key' => 'reschedule_window_hours'],
            [
                'key' => 'reschedule_window_hours',
                'value' => '24', // Default to 24 hours
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove reschedule window setting
        DB::table('company_settings')->where('key', 'reschedule_window_hours')->delete();
    }
};

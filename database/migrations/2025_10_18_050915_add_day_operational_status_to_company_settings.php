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
        // Add operational status for each day (1 = operational, 0 = closed)
        $settings = [
            ['key' => 'operating_hours_monday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_tuesday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_wednesday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_thursday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_friday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_saturday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_sunday_operational', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($settings as $setting) {
            // Only insert if the key doesn't already exist
            if (!DB::table('company_settings')->where('key', $setting['key'])->exists()) {
                DB::table('company_settings')->insert($setting);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove operational status fields
        $keys = [
            'operating_hours_monday_operational',
            'operating_hours_tuesday_operational',
            'operating_hours_wednesday_operational',
            'operating_hours_thursday_operational',
            'operating_hours_friday_operational',
            'operating_hours_saturday_operational',
            'operating_hours_sunday_operational',
        ];

        DB::table('company_settings')->whereIn('key', $keys)->delete();
    }
};

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
        // Insert default operating hours settings
        $settings = [
            ['key' => 'operating_hours_opening', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_closing', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_monday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_monday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_tuesday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_tuesday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_wednesday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_wednesday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_thursday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_thursday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_friday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_friday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_saturday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_saturday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_sunday_open', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_sunday_close', 'value' => '22:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'operating_hours_enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
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
        // Remove operating hours settings
        $keys = [
            'operating_hours_opening',
            'operating_hours_closing',
            'operating_hours_monday_open',
            'operating_hours_monday_close',
            'operating_hours_tuesday_open',
            'operating_hours_tuesday_close',
            'operating_hours_wednesday_open',
            'operating_hours_wednesday_close',
            'operating_hours_thursday_open',
            'operating_hours_thursday_close',
            'operating_hours_friday_open',
            'operating_hours_friday_close',
            'operating_hours_saturday_open',
            'operating_hours_saturday_close',
            'operating_hours_sunday_open',
            'operating_hours_sunday_close',
            'operating_hours_enabled',
        ];

        DB::table('company_settings')->whereIn('key', $keys)->delete();
    }
};

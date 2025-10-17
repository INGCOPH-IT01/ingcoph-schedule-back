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
        // Insert subtitle settings for all modules
        $settings = [
            // Courts subtitle
            ['key' => 'module_courts_subtitle', 'value' => 'Create, manage, and configure courts for all sports'],
            
            // Sports subtitle
            ['key' => 'module_sports_subtitle', 'value' => 'Configure available sports and their settings'],
            
            // Bookings subtitle
            ['key' => 'module_bookings_subtitle', 'value' => 'View and manage your court reservations'],
            
            // Users subtitle
            ['key' => 'module_users_subtitle', 'value' => 'Manage users, staff, and administrators'],
            
            // Admin subtitle
            ['key' => 'module_admin_subtitle', 'value' => 'Manage multi-sport court bookings and oversee the entire system with professional precision'],
        ];

        foreach ($settings as $setting) {
            DB::table('company_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove subtitle settings
        DB::table('company_settings')->whereIn('key', [
            'module_courts_subtitle',
            'module_sports_subtitle',
            'module_bookings_subtitle',
            'module_users_subtitle',
            'module_admin_subtitle',
        ])->delete();
    }
};

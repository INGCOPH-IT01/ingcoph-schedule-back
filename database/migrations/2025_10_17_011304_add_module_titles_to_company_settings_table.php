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
        // Insert default module titles settings as key-value pairs
        $settings = [
            // Module Titles - Courts
            ['key' => 'module_courts_text', 'value' => 'Manage Courts'],
            ['key' => 'module_courts_color', 'value' => '#B71C1C'],
            ['key' => 'module_courts_badge_color', 'value' => '#D32F2F'],
            
            // Module Titles - Sports
            ['key' => 'module_sports_text', 'value' => 'Manage Sports'],
            ['key' => 'module_sports_color', 'value' => '#B71C1C'],
            ['key' => 'module_sports_badge_color', 'value' => '#D32F2F'],
            
            // Module Titles - Bookings
            ['key' => 'module_bookings_text', 'value' => 'My Bookings'],
            ['key' => 'module_bookings_color', 'value' => '#B71C1C'],
            ['key' => 'module_bookings_badge_color', 'value' => '#D32F2F'],
            
            // Module Titles - Users
            ['key' => 'module_users_text', 'value' => 'Manage Users'],
            ['key' => 'module_users_color', 'value' => '#B71C1C'],
            ['key' => 'module_users_badge_color', 'value' => '#D32F2F'],
            
            // Module Titles - Admin Panel
            ['key' => 'module_admin_text', 'value' => 'Admin Panel'],
            ['key' => 'module_admin_color', 'value' => '#B71C1C'],
            ['key' => 'module_admin_badge_color', 'value' => '#D32F2F'],
            
            // Theme Gradient Settings
            ['key' => 'theme_gradient_color1', 'value' => '#FFFFFF'],
            ['key' => 'theme_gradient_color2', 'value' => '#FFF5F5'],
            ['key' => 'theme_gradient_color3', 'value' => '#FFEBEE'],
            ['key' => 'theme_gradient_angle', 'value' => '135'],
            
            // Theme Button Colors
            ['key' => 'theme_button_primary_color', 'value' => '#B71C1C'],
            ['key' => 'theme_button_secondary_color', 'value' => '#5F6368'],
            ['key' => 'theme_button_success_color', 'value' => '#4CAF50'],
            ['key' => 'theme_button_error_color', 'value' => '#D32F2F'],
            ['key' => 'theme_button_warning_color', 'value' => '#F57C00'],
            ['key' => 'theme_button_info_color', 'value' => '#757575'],
            
            // Settings version tracker
            ['key' => 'settings_version', 'value' => '1'],
            ['key' => 'settings_updated_at', 'value' => now()->toISOString()],
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
        // Remove module titles settings
        DB::table('company_settings')->whereIn('key', [
            'module_courts_text',
            'module_courts_color',
            'module_courts_badge_color',
            'module_sports_text',
            'module_sports_color',
            'module_sports_badge_color',
            'module_bookings_text',
            'module_bookings_color',
            'module_bookings_badge_color',
            'module_users_text',
            'module_users_color',
            'module_users_badge_color',
            'module_admin_text',
            'module_admin_color',
            'module_admin_badge_color',
            'theme_gradient_color1',
            'theme_gradient_color2',
            'theme_gradient_color3',
            'theme_gradient_angle',
            'theme_button_primary_color',
            'theme_button_secondary_color',
            'theme_button_success_color',
            'theme_button_error_color',
            'theme_button_warning_color',
            'theme_button_info_color',
            'settings_version',
            'settings_updated_at',
        ])->delete();
    }
};

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
        // Insert default background color settings as key-value pairs
        $settings = [
            ['key' => 'bg_primary_color', 'value' => '#FFFFFF'],
            ['key' => 'bg_secondary_color', 'value' => '#FFEBEE'],
            ['key' => 'bg_accent_color', 'value' => '#FFCDD2'],
            ['key' => 'bg_overlay_color', 'value' => 'rgba(183, 28, 28, 0.08)'],
            ['key' => 'bg_pattern_color', 'value' => 'rgba(183, 28, 28, 0.03)'],
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
        // Remove background color settings
        DB::table('company_settings')->whereIn('key', [
            'bg_primary_color',
            'bg_secondary_color',
            'bg_accent_color',
            'bg_overlay_color',
            'bg_pattern_color'
        ])->delete();
    }
};


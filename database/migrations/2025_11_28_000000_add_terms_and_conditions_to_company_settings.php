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
        // Insert default terms and conditions settings
        $settings = [
            [
                'key' => 'terms_and_conditions',
                'value' => '',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'terms_enabled',
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('company_settings')->updateOrInsert(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove terms and conditions settings
        DB::table('company_settings')->whereIn('key', [
            'terms_and_conditions',
            'terms_enabled'
        ])->delete();
    }
};

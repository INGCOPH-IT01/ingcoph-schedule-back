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
        // Insert default payment settings as key-value pairs
        $settings = [
            ['key' => 'payment_gcash_number', 'value' => '0917-123-4567'],
            ['key' => 'payment_gcash_name', 'value' => 'Perfect Smash'],
            ['key' => 'payment_instructions', 'value' => 'Please send payment to our GCash number and upload proof of payment.'],
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
        // Remove payment settings
        DB::table('company_settings')->whereIn('key', [
            'payment_gcash_number',
            'payment_gcash_name',
            'payment_instructions'
        ])->delete();
    }
};

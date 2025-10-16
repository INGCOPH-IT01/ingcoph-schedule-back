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
        // Only insert if the table exists
        if (Schema::hasTable('company_settings')) {
            // Insert company_logo key with null value by default
            DB::table('company_settings')->insert([
                'key' => 'company_logo',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove company_logo key
        DB::table('company_settings')->where('key', 'company_logo')->delete();
    }
};

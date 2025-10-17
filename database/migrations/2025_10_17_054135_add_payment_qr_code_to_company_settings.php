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
        // Insert payment QR code setting as key-value pair
        DB::table('company_settings')->updateOrInsert(
            ['key' => 'payment_qr_code'],
            [
                'key' => 'payment_qr_code',
                'value' => null,
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
        // Remove payment QR code setting
        DB::table('company_settings')->where('key', 'payment_qr_code')->delete();
    }
};

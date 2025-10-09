<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->text('qr_code')->nullable()->after('proof_of_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->dropColumn('qr_code');
        });
    }
};
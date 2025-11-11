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
            $table->decimal('pos_amount', 10, 2)->default(0)->after('total_price'); // Amount from POS items
            $table->decimal('booking_amount', 10, 2)->default(0)->after('pos_amount'); // Amount from booking
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->dropColumn(['pos_amount', 'booking_amount']);
        });
    }
};


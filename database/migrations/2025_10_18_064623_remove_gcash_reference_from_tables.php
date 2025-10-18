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
        // Remove gcash_reference from bookings table
        if (Schema::hasColumn('bookings', 'gcash_reference')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('gcash_reference');
            });
        }

        // Remove gcash_reference from cart_transactions table
        if (Schema::hasColumn('cart_transactions', 'gcash_reference')) {
            Schema::table('cart_transactions', function (Blueprint $table) {
                $table->dropColumn('gcash_reference');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add gcash_reference back to bookings table
        if (!Schema::hasColumn('bookings', 'gcash_reference')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('gcash_reference')->nullable()->after('payment_status');
            });
        }

        // Add gcash_reference back to cart_transactions table
        if (!Schema::hasColumn('cart_transactions', 'gcash_reference')) {
            Schema::table('cart_transactions', function (Blueprint $table) {
                $table->string('gcash_reference')->nullable()->after('payment_status');
            });
        }
    }
};

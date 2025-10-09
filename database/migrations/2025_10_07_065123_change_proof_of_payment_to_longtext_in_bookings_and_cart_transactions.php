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
        // Change proof_of_payment to LONGTEXT in bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->longText('proof_of_payment')->nullable()->change();
        });

        // Change proof_of_payment to LONGTEXT in cart_transactions table
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->longText('proof_of_payment')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to TEXT in bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('proof_of_payment')->nullable()->change();
        });

        // Revert to TEXT in cart_transactions table
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->text('proof_of_payment')->nullable()->change();
        });
    }
};

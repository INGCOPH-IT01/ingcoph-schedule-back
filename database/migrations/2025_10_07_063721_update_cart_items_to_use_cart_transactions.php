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
        Schema::table('cart_items', function (Blueprint $table) {
            // Drop the old booking_id foreign key if it exists
            if (Schema::hasColumn('cart_items', 'booking_id')) {
                $table->dropForeign(['booking_id']);
                $table->dropIndex(['booking_id']);
                $table->dropColumn('booking_id');
            }
            
            // Add cart_transaction_id
            $table->foreignId('cart_transaction_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            $table->index('cart_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // Drop cart_transaction_id
            $table->dropForeign(['cart_transaction_id']);
            $table->dropIndex(['cart_transaction_id']);
            $table->dropColumn('cart_transaction_id');
            
            // Restore booking_id
            $table->foreignId('booking_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            $table->index('booking_id');
        });
    }
};

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
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('cart_transaction_id')->nullable()->after('user_id')->constrained()->onDelete('set null');
            $table->index('cart_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['cart_transaction_id']);
            $table->dropIndex(['cart_transaction_id']);
            $table->dropColumn('cart_transaction_id');
        });
    }
};

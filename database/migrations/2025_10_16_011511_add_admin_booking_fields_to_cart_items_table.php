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
            // Add admin booking fields
            $table->foreignId('booking_for_user_id')->nullable()->after('user_id')->constrained('users')->onDelete('set null');
            $table->string('booking_for_user_name')->nullable()->after('booking_for_user_id');
            $table->text('admin_notes')->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['booking_for_user_id']);
            $table->dropColumn(['booking_for_user_id', 'booking_for_user_name', 'admin_notes']);
        });
    }
};

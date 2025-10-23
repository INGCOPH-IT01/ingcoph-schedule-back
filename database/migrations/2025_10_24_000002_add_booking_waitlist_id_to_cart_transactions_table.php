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
            $table->unsignedBigInteger('booking_waitlist_id')->nullable()->after('user_id');

            $table->foreign('booking_waitlist_id')
                ->references('id')
                ->on('booking_waitlists')
                ->onDelete('set null');

            $table->index('booking_waitlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->dropForeign(['booking_waitlist_id']);
            $table->dropIndex(['booking_waitlist_id']);
            $table->dropColumn('booking_waitlist_id');
        });
    }
};

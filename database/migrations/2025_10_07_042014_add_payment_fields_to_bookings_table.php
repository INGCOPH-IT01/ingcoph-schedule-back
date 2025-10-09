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
            if (!Schema::hasColumn('bookings', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->after('payment_method');
            }
            if (!Schema::hasColumn('bookings', 'gcash_reference')) {
                $table->string('gcash_reference')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('bookings', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('gcash_reference');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
            if (Schema::hasColumn('bookings', 'gcash_reference')) {
                $table->dropColumn('gcash_reference');
            }
            if (Schema::hasColumn('bookings', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
        });
    }
};
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
            $table->enum('attendance_status', ['not_set', 'showed_up', 'no_show'])->default('not_set')->after('qr_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->dropColumn('attendance_status');
        });
    }
};

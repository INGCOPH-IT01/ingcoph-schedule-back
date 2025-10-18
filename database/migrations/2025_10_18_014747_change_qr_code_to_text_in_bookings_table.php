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
            // First drop the unique constraint
            $table->dropUnique('bookings_qr_code_unique');
            // Then change the column type to text
            $table->text('qr_code')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Change back to string and add unique constraint
            $table->string('qr_code')->nullable()->unique()->change();
        });
    }
};

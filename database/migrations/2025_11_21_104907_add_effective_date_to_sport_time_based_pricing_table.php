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
        Schema::table('sport_time_based_pricing', function (Blueprint $table) {
            $table->dateTime('effective_date')->nullable()->after('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sport_time_based_pricing', function (Blueprint $table) {
            $table->dropColumn('effective_date');
        });
    }
};

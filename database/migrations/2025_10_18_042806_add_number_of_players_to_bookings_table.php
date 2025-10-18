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
            $table->integer('number_of_players')->default(1)->after('total_price');
            $table->integer('attendance_scan_count')->default(0)->after('checked_in_at');
            $table->integer('players_attended')->nullable()->after('attendance_scan_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['number_of_players', 'attendance_scan_count', 'players_attended']);
        });
    }
};

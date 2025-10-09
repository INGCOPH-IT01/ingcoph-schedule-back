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
            $table->foreignId('recurring_schedule_id')->nullable()->constrained()->onDelete('set null');
            $table->index(['recurring_schedule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['recurring_schedule_id']);
            $table->dropIndex(['recurring_schedule_id']);
            $table->dropColumn('recurring_schedule_id');
        });
    }
};

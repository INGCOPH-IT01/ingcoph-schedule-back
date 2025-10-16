<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if foreign key exists using raw SQL
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'bookings' 
            AND COLUMN_NAME = 'recurring_schedule_id'
            AND CONSTRAINT_NAME != 'PRIMARY'
        ");

        // Drop foreign key if it exists
        foreach ($foreignKeys as $fk) {
            DB::statement("ALTER TABLE bookings DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
        }

        // Drop the column if it exists
        if (Schema::hasColumn('bookings', 'recurring_schedule_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('recurring_schedule_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('recurring_schedule_id')->nullable()->constrained('recurring_schedules')->onDelete('set null');
        });
    }
};

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
        // Check if the column still exists before trying to drop foreign key
        if (Schema::hasColumn('bookings', 'recurring_schedule_id')) {
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
            
            // Then drop the column
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('recurring_schedule_id');
            });
        }
        
        // Finally, drop the recurring_schedules table
        Schema::dropIfExists('recurring_schedules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the recurring_schedules table
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('duration_hours', 8, 2)->default(0);
            $table->enum('recurrence_type', ['daily', 'weekly', 'weekly_multiple_times', 'monthly', 'yearly', 'yearly_multiple_times']);
            $table->json('recurrence_days')->nullable();
            $table->json('day_specific_times')->nullable();
            $table->integer('recurrence_interval')->default(1);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('max_occurrences')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        
        // Recreate the foreign key column
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('recurring_schedule_id')->nullable()->constrained('recurring_schedules')->onDelete('set null');
        });
    }
};

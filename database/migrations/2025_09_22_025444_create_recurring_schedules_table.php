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
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->string('title'); // e.g., "Weekly Badminton Practice"
            $table->text('description')->nullable();
            
            // Schedule details
            $table->time('start_time'); // e.g., 20:00 (8 PM)
            $table->time('end_time'); // e.g., 21:00 (9 PM)
            $table->integer('duration_hours'); // calculated duration
            
            // Recurrence pattern
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly']);
            $table->json('recurrence_days'); // [1,2,3,4,5] for weekdays, [1] for Monday, etc.
            $table->integer('recurrence_interval')->default(1); // every 1 week, 2 weeks, etc.
            
            // Date range
            $table->date('start_date'); // when the recurring schedule starts
            $table->date('end_date')->nullable(); // when it ends (null = no end date)
            $table->integer('max_occurrences')->nullable(); // limit number of occurrences
            
            // Status and settings
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_approve')->default(false); // auto-approve generated bookings
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'is_active']);
            $table->index(['court_id', 'is_active']);
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_schedules');
    }
};

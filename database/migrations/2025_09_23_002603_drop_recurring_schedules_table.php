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
        Schema::dropIfExists('recurring_schedules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the recurring_schedules table if needed
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
    }
};

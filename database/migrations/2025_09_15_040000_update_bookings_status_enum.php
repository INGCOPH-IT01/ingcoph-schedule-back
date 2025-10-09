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
        // For SQLite, we need to recreate the table with the new enum values
        Schema::create('bookings_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->datetime('start_time');
            $table->datetime('end_time');
            $table->decimal('total_price', 8, 2);
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled', 'completed'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['court_id', 'start_time', 'end_time']);
        });

        // Copy data from old table to new table, converting 'confirmed' to 'approved'
        $bookings = DB::table('bookings')->get();
        foreach ($bookings as $booking) {
            $status = $booking->status === 'confirmed' ? 'approved' : $booking->status;
            DB::table('bookings_new')->insert([
                'id' => $booking->id,
                'user_id' => $booking->user_id,
                'court_id' => $booking->court_id,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'total_price' => $booking->total_price,
                'status' => $status,
                'notes' => $booking->notes,
                'created_at' => $booking->created_at,
                'updated_at' => $booking->updated_at,
            ]);
        }

        // Drop old table and rename new table
        Schema::dropIfExists('bookings');
        Schema::rename('bookings_new', 'bookings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the original table structure
        Schema::create('bookings_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->datetime('start_time');
            $table->datetime('end_time');
            $table->decimal('total_price', 8, 2);
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['court_id', 'start_time', 'end_time']);
        });

        // Copy data back, converting 'approved' to 'confirmed'
        $bookings = DB::table('bookings')->get();
        foreach ($bookings as $booking) {
            $status = $booking->status === 'approved' ? 'confirmed' : $booking->status;
            DB::table('bookings_old')->insert([
                'id' => $booking->id,
                'user_id' => $booking->user_id,
                'court_id' => $booking->court_id,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'total_price' => $booking->total_price,
                'status' => $status,
                'notes' => $booking->notes,
                'created_at' => $booking->created_at,
                'updated_at' => $booking->updated_at,
            ]);
        }

        // Drop current table and rename old table
        Schema::dropIfExists('bookings');
        Schema::rename('bookings_old', 'bookings');
    }
};

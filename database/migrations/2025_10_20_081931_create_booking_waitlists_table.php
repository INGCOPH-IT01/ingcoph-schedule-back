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
        Schema::create('booking_waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('pending_booking_id')->nullable()->constrained('bookings')->onDelete('cascade');
            $table->foreignId('pending_cart_transaction_id')->nullable()->constrained('cart_transactions')->onDelete('cascade');
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->foreignId('sport_id')->constrained()->onDelete('cascade');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('number_of_players')->default(1);
            $table->integer('position')->default(0); // Position in waitlist queue
            $table->enum('status', ['pending', 'notified', 'converted', 'expired', 'cancelled'])->default('pending');
            $table->dateTime('notified_at')->nullable(); // When email was sent (starts expiration timer)
            $table->dateTime('expires_at')->nullable(); // When the waitlist entry expires
            $table->foreignId('converted_cart_transaction_id')->nullable()->constrained('cart_transactions')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for better query performance
            $table->index(['court_id', 'start_time', 'end_time']);
            $table->index(['status', 'notified_at']);
            $table->index(['pending_booking_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_waitlists');
    }
};

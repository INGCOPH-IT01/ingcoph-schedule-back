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
        Schema::create('waitlist_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_for_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('booking_for_user_name')->nullable();
            $table->foreignId('waitlist_cart_transaction_id')->nullable()->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('booking_waitlist_id')->nullable();
            $table->foreignId('court_id')->constrained()->onDelete('cascade');
            $table->foreignId('sport_id')->nullable()->constrained()->onDelete('cascade');
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('price', 10, 2);
            $table->integer('number_of_players')->default(1);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('session_id')->nullable(); // For guest users
            $table->timestamps();

            // Foreign key for booking_waitlist_id
            $table->foreign('booking_waitlist_id')
                ->references('id')
                ->on('booking_waitlists')
                ->onDelete('set null');

            // Indexes for faster queries
            $table->index(['user_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
            $table->index('waitlist_cart_transaction_id');
            $table->index('booking_waitlist_id');
            $table->index('sport_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_cart_items');
    }
};

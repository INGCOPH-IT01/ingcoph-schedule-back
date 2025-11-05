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
        Schema::create('waitlist_cart_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_for_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('booking_for_user_name')->nullable();
            $table->unsignedBigInteger('booking_waitlist_id')->nullable();
            $table->decimal('total_price', 10, 2);
            $table->string('status')->default('pending'); // pending, completed, cancelled
            $table->string('approval_status')->default('pending'); // pending, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payment_method')->default('pending'); // pending, gcash
            $table->string('payment_status')->default('unpaid'); // unpaid, paid
            $table->longText('proof_of_payment')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('qr_code')->nullable();
            $table->enum('attendance_status', ['not_set', 'showed_up', 'no_show'])->default('not_set');
            $table->timestamps();

            // Foreign key for booking_waitlist_id
            $table->foreign('booking_waitlist_id')
                ->references('id')
                ->on('booking_waitlists')
                ->onDelete('set null');

            // Indexes for faster queries
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('booking_waitlist_id');
            $table->index(['approval_status', 'payment_status', 'created_at'], 'waitlist_cart_trans_approval_payment_created');
            $table->index(['status', 'payment_status'], 'waitlist_cart_trans_status_payment');
            $table->index(['user_id', 'status', 'created_at'], 'waitlist_cart_trans_user_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_cart_transactions');
    }
};

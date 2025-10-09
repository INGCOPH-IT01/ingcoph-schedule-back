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
        Schema::create('cart_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('total_price', 10, 2);
            $table->string('status')->default('pending'); // pending, completed, cancelled
            $table->string('payment_method')->default('pending'); // pending, gcash
            $table->string('payment_status')->default('unpaid'); // unpaid, paid
            $table->string('gcash_reference')->nullable();
            $table->text('proof_of_payment')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            
            // Index for faster queries
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_transactions');
    }
};

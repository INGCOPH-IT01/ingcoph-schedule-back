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
        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number')->unique(); // e.g., POS-2024-00001
            $table->foreignId('booking_id')->nullable()->constrained('cart_transactions')->onDelete('set null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Staff/admin who processed the sale
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('set null'); // Optional customer
            $table->string('customer_name')->nullable(); // For walk-in customers
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->string('payment_method')->nullable(); // cash, gcash, card, etc.
            $table->string('payment_reference')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled', 'refunded'])->default('completed');
            $table->text('notes')->nullable();
            $table->timestamp('sale_date')->useCurrent();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};


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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Who made the change
            $table->enum('type', ['in', 'out', 'adjustment', 'sale', 'return', 'damage']); // Type of movement
            $table->integer('quantity'); // Positive for in, negative for out
            $table->integer('quantity_before'); // Stock before movement
            $table->integer('quantity_after'); // Stock after movement
            $table->foreignId('pos_sale_id')->nullable()->constrained()->onDelete('set null'); // If related to a sale
            $table->text('notes')->nullable();
            $table->string('reference_number')->nullable(); // Invoice number, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};


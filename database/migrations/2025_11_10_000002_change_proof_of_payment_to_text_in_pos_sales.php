<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Changes proof_of_payment from LONGTEXT to TEXT since we now store
     * file paths instead of base64-encoded images.
     */
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            // Change from LONGTEXT to TEXT (file paths are much smaller than base64)
            $table->text('proof_of_payment')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            // Revert back to LONGTEXT
            $table->longText('proof_of_payment')->nullable()->change();
        });
    }
};

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
        });

        // Migrate existing data from name column to first_name and last_name
        DB::statement('UPDATE users SET first_name = SUBSTRING_INDEX(name, " ", 1), last_name = TRIM(SUBSTRING(name, LENGTH(SUBSTRING_INDEX(name, " ", 1)) + 1))');

        // For users with only one name (no space), set last_name to empty string
        DB::statement('UPDATE users SET last_name = "" WHERE last_name IS NULL');

        // Make the columns non-nullable now that data is migrated
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};

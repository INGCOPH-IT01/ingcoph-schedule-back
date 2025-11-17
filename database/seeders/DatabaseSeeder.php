<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed admin and regular users
        $this->call([
            AdminUserSeeder::class,
        ]);

        // Seed sports and courts
        $this->call([
            SportSeeder::class,
            CourtSeeder::class,
        ]);

        // Note: For data consistency checks and fixes, run separately:
        // php artisan db:seed --class=DataConsistencyAnalyzerSeeder
    }
}

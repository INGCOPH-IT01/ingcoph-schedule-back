<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Sport::create([
            'name' => 'Badminton',
            'description' => 'Racquet sport played with a shuttlecock on a rectangular court',
            'price_per_hour' => 150.00,
            'is_active' => true,
        ]);

        \App\Models\Sport::create([
            'name' => 'Basketball',
            'description' => 'Team sport played on a rectangular court with a hoop at each end',
            'price_per_hour' => 200.00,
            'is_active' => true,
        ]);

        \App\Models\Sport::create([
            'name' => 'Volleyball',
            'description' => 'Team sport played over a net with a ball',
            'price_per_hour' => 180.00,
            'is_active' => true,
        ]);

        \App\Models\Sport::create([
            'name' => 'Tennis',
            'description' => 'Racket sport played on a rectangular court with a net',
            'price_per_hour' => 250.00,
            'is_active' => true,
        ]);
    }
}

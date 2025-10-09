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
            'is_active' => true,
        ]);
    }
}

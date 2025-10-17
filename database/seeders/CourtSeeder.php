<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourtSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Single Badminton Court
        \App\Models\Court::create([
            'name' => 'Badminton Court',
            'sport_id' => 1,
            'description' => 'Professional badminton court with wooden floor and modern facilities',
            'location' => 'Main Sports Hall',
            'amenities' => ['Air Conditioning', 'Equipment Rental', 'Parking', 'Professional Lighting', 'Sound System'],
            'is_active' => true,
        ]);
    }
}

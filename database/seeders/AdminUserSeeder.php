<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Badminton Admin',
            'email' => 'admin@badminton.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin'
        ]);

        // Create regular user for testing
        User::create([
            'name' => 'Test User',
            'email' => 'user@badminton.com',
            'password' => Hash::make('user123'),
            'role' => 'user'
        ]);
    }
}

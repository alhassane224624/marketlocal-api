<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Admin MarketLocal',
            'email' => 'admin@marketlocal.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Amina Vendeuse',
            'email' => 'vendeur@marketlocal.test',
            'password' => Hash::make('password'),
            'role' => 'vendeur',
        ]);

        User::create([
            'name' => 'Karim Acheteur',
            'email' => 'acheteur@marketlocal.test',
            'password' => Hash::make('password'),
            'role' => 'acheteur',
        ]);
    }
}
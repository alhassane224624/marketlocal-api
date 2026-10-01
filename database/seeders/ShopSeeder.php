<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shop;
use App\Models\User;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $vendeur = User::where('role', 'vendeur')->first();

        Shop::create([
            'user_id' => $vendeur->id,
            'nom' => 'Boutique Amina Artisanat',
            'description' => 'Produits artisanaux faits main, inspirés du savoir-faire local.',
            'statut' => 'valide',
        ]);
    }
}
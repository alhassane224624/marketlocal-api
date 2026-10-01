<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Category;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Shop::first();
        $categorieArtisanat = Category::where('nom', 'Artisanat')->first();
        $categorieDeco = Category::where('nom', 'Décoration')->first();

        Product::create([
            'shop_id' => $shop->id,
            'category_id' => $categorieArtisanat->id,
            'nom' => 'Panier tressé en osier',
            'description' => 'Panier artisanal tressé à la main, idéal pour le rangement ou la décoration.',
            'prix' => 150.00,
            'stock' => 20,
        ]);

        Product::create([
            'shop_id' => $shop->id,
            'category_id' => $categorieArtisanat->id,
            'nom' => 'Tapis berbère traditionnel',
            'description' => 'Tapis tissé selon les techniques traditionnelles, motifs authentiques.',
            'prix' => 890.00,
            'stock' => 5,
        ]);

        Product::create([
            'shop_id' => $shop->id,
            'category_id' => $categorieDeco->id,
            'nom' => 'Lanterne marocaine en cuivre',
            'description' => 'Lanterne décorative artisanale en cuivre martelé.',
            'prix' => 320.00,
            'stock' => 12,
        ]);
    }
}
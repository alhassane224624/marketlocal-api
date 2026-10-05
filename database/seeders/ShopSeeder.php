<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $shops = [
            ['vendeur@marketlocal.test', 'Atelier Amina', 'Vannerie, tapis et luminaires faits main à Marrakech, dans le respect des gestes transmis de mère en fille.', 'valide', 10],
            ['youssef@marketlocal.test', 'Coopérative Argan Souss', "Huile d'argan, amlou et miels de montagne produits par une coopérative de femmes près d'Agadir.", 'valide', 8],
            ['salma@marketlocal.test', 'Salma Textiles', 'Tissages, foutas et vêtements en coton naturel teints à la main à Fès.', 'valide', 12],
            // En attente : permet de tester la validation côté admin.
            ['omar@marketlocal.test', 'Épicerie Fine Omar', 'Épices, olives et conserves artisanales de la région de Meknès.', 'en_attente', 10],
        ];

        foreach ($shops as [$email, $nom, $description, $statut, $commission]) {
            Shop::create([
                'user_id' => User::where('email', $email)->value('id'),
                'nom' => $nom,
                'description' => $description,
                'statut' => $statut,
                'commission' => $commission,
            ]);
        }
    }
}

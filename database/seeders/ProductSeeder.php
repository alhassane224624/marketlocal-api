<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['Atelier Amina', 'Artisanat', 'Panier tressé en osier', 'Panier artisanal tressé à la main, idéal pour le rangement ou le marché.', 150, 20],
            ['Atelier Amina', 'Artisanat', 'Tapis berbère Beni Ouarain', 'Tapis en laine tissé selon les techniques traditionnelles de l’Atlas, motifs losanges.', 2890, 3],
            ['Atelier Amina', 'Décoration', 'Lanterne en cuivre ciselé', 'Lanterne décorative en cuivre martelé et ajouré, projette des motifs étoilés.', 320, 12],
            ['Atelier Amina', 'Décoration', 'Plateau en bois de thuya', 'Plateau sculpté dans la loupe de thuya d’Essaouira, finition cire naturelle.', 260, 8],
            ['Atelier Amina', 'Artisanat', 'Tajine décoratif peint main', 'Tajine en terre cuite émaillée, décor floral peint au pinceau.', 180, 0],
            ['Coopérative Argan Souss', 'Cosmétiques naturels', 'Huile d’argan pure 100 ml', 'Huile d’argan cosmétique pressée à froid, certifiée bio.', 140, 40],
            ['Coopérative Argan Souss', 'Alimentation locale', 'Amlou traditionnel 250 g', 'Pâte d’amandes torréfiées, huile d’argan alimentaire et miel.', 95, 25],
            ['Coopérative Argan Souss', 'Alimentation locale', 'Miel de thym de l’Atlas', 'Miel cru récolté en altitude, saveur intense et florale.', 160, 4],
            ['Coopérative Argan Souss', 'Cosmétiques naturels', 'Savon noir à l’eucalyptus', 'Savon noir beldi pour le hammam, enrichi en huile d’olive.', 45, 60],
            ['Coopérative Argan Souss', 'Cosmétiques naturels', 'Ghassoul de l’Atlas', 'Argile minérale lavante, pour le visage et les cheveux.', 55, 30],
            ['Salma Textiles', 'Vêtements & textiles', 'Fouta rayée en coton', 'Fouta tissée main, légère et absorbante, idéale pour la plage ou le hammam.', 120, 35],
            ['Salma Textiles', 'Vêtements & textiles', 'Djellaba en lin naturel', 'Djellaba coupe ample en lin lavé, broderies ton sur ton.', 690, 6],
            ['Salma Textiles', 'Décoration', 'Coussin en tissage berbère', 'Housse de coussin 45×45 tissée main, motifs géométriques.', 210, 14],
            ['Salma Textiles', 'Vêtements & textiles', 'Babouches en cuir de Fès', 'Babouches cousues main en cuir tanné végétal.', 240, 2],
        ];

        foreach ($products as [$shop, $category, $nom, $description, $prix, $stock]) {
            Product::create([
                'shop_id' => Shop::where('nom', $shop)->value('id'),
                'category_id' => Category::where('nom', $category)->value('id'),
                'nom' => $nom,
                'description' => $description,
                'prix' => $prix,
                'stock' => $stock,
            ]);
        }
    }
}

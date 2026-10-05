<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Commandes et avis de démonstration, pour que les tableaux de bord
 * (acheteur, vendeur, admin) ne soient pas vides au premier lancement.
 */
class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        $karim = User::where('email', 'acheteur@marketlocal.test')->first();
        $lina = User::where('email', 'lina@marketlocal.test')->first();

        $this->order($karim, 'livree', 24, ['Panier tressé en osier' => 2, 'Huile d’argan pure 100 ml' => 1]);
        $this->order($karim, 'expediee', 6, ['Lanterne en cuivre ciselé' => 1]);
        $this->order($karim, 'payee', 2, ['Fouta rayée en coton' => 2, 'Amlou traditionnel 250 g' => 1]);
        $this->order($lina, 'livree', 18, ['Coussin en tissage berbère' => 2, 'Savon noir à l’eucalyptus' => 3]);
        $this->order($lina, 'livree', 11, ['Plateau en bois de thuya' => 1, 'Miel de thym de l’Atlas' => 1]);
        $this->order($lina, 'payee', 1, ['Djellaba en lin naturel' => 1]);

        $reviews = [
            [$karim, 'Panier tressé en osier', 5, 'Très bien fini, exactement comme sur la fiche. Livraison rapide.'],
            [$karim, 'Huile d’argan pure 100 ml', 4, 'Huile de qualité, je recommande. Flacon un peu petit.'],
            [$lina, 'Coussin en tissage berbère', 5, 'Magnifique, les couleurs sont encore plus belles en vrai.'],
            [$lina, 'Savon noir à l’eucalyptus', 4, 'Parfait pour le hammam, parfum agréable.'],
            [$lina, 'Plateau en bois de thuya', 5, 'Un vrai travail d’artisan, l’odeur du thuya est incroyable.'],
        ];

        foreach ($reviews as [$user, $nom, $note, $commentaire]) {
            Review::create([
                'user_id' => $user->id,
                'product_id' => Product::where('nom', $nom)->value('id'),
                'note' => $note,
                'commentaire' => $commentaire,
            ]);
        }
    }

    private function order(User $buyer, string $statut, int $daysAgo, array $lines): void
    {
        $date = now()->subDays($daysAgo);
        $products = Product::with('shop')->whereIn('nom', array_keys($lines))->get();
        $total = $products->sum(fn ($p) => (float) $p->prix * $lines[$p->nom]);

        $order = Order::create([
            'buyer_id' => $buyer->id,
            'statut' => $statut,
            'total' => $total,
            'adresse_livraison' => $buyer->adresse,
            'ville_livraison' => $buyer->ville,
            'telephone_livraison' => $buyer->telephone,
            'paid_at' => $date,
        ]);
        $order->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();

        foreach ($products as $product) {
            $gross = (float) $product->prix * $lines[$product->nom];
            $commission = round($gross * (float) $product->shop->commission / 100, 2);

            $order->items()->create([
                'product_id' => $product->id,
                'shop_id' => $product->shop_id,
                'product_nom' => $product->nom,
                'quantite' => $lines[$product->nom],
                'statut' => $statut,
                'prix_unitaire' => $product->prix,
                'taux_commission' => $product->shop->commission,
                'commission_amount' => $commission,
                'seller_amount' => $gross - $commission,
                // Comptes vendeurs de démo non reliés à Stripe : versement en attente d'activation.
                'seller_transfer_status' => 'blocked',
            ]);
        }
    }
}

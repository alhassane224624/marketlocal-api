<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Toutes les routes de ce contrôleur sont protégées par le middleware
// role:admin (voir routes/api.php) : plus de vérification manuelle ici.
class AdminController extends Controller
{
    // GET /api/admin/stats
    public function stats()
    {
        return response()->json([
            'total_utilisateurs' => User::count(),
            'total_acheteurs' => User::where('role', 'acheteur')->count(),
            'total_vendeurs' => User::where('role', 'vendeur')->count(),
            'total_livreurs' => User::where('role', 'livreur')->count(),
            // Frais de livraison des courses livrées moins la part des livreurs.
            'marge_livraison' => round((float) Delivery::where('statut', 'livree')->sum(DB::raw('frais - remuneration_livreur')), 2),
            'total_boutiques' => Shop::count(),
            'boutiques_en_attente' => Shop::where('statut', 'en_attente')->count(),
            'litiges_ouverts' => Delivery::whereNotNull('probleme_signale_at')->whereNull('litige_resolu_at')->count(),
            'total_produits' => Product::count(),
            'total_commandes' => Order::count(),
            // Argent réellement encaissé (voir OrderItem::scopeEncaissees) : les commandes carte
            // non payées ne comptent plus. (float) : sum() renvoie une chaîne via PDO.
            'chiffre_affaires_total' => round((float) OrderItem::encaissees()->sum(DB::raw('quantite * prix_unitaire')), 2),
            // Revenu de la plateforme : commissions figées sur les lignes encaissées.
            'commissions_total' => round((float) OrderItem::encaissees()->sum('commission_amount'), 2),
        ]);
    }

    // GET /api/admin/livreurs : activité et rémunération restant à verser à chaque livreur.
    public function livreurs()
    {
        $livreurs = User::where('role', 'livreur')
            ->withCount(['deliveries as courses_livrees' => fn ($q) => $q->where('statut', 'livree')])
            ->withCount(['deliveries as courses_en_cours' => fn ($q) => $q->whereIn('statut', ['acceptee', 'en_route'])])
            ->withSum(['deliveries as gains_total' => fn ($q) => $q->where('statut', 'livree')], 'remuneration_livreur')
            ->withSum(['deliveries as a_payer' => fn ($q) => $q->payables()->whereNull('remuneration_payee_at')], 'remuneration_livreur')
            ->withCount(['deliveries as litiges' => fn ($q) => $q->whereNotNull('probleme_signale_at')->whereNull('litige_resolu_at')])
            ->orderBy('name')
            ->get();

        return response()->json($livreurs->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'telephone' => $u->telephone,
            'ville' => $u->ville,
            'courses_livrees' => (int) $u->courses_livrees,
            'courses_en_cours' => (int) $u->courses_en_cours,
            'gains_total' => round((float) $u->gains_total, 2),
            'a_payer' => round((float) $u->a_payer, 2),
            'litiges' => (int) $u->litiges,
        ])->values());
    }

    // POST /api/admin/livreurs/{user}/payer : marque comme versée la rémunération due.
    public function payLivreur(User $user)
    {
        if ($user->role !== 'livreur') {
            return response()->json(['message' => "Cet utilisateur n'est pas livreur."], 422);
        }

        // Seulement les courses dont le client a confirmé la réception, sans litige.
        $montant = (float) $user->deliveries()->payables()->whereNull('remuneration_payee_at')->sum('remuneration_livreur');
        $user->deliveries()->payables()->whereNull('remuneration_payee_at')->update(['remuneration_payee_at' => now()]);

        return response()->json(['message' => 'Rémunération marquée comme versée.', 'montant' => round($montant, 2)]);
    }

    // GET /api/admin/users
    public function users()
    {
        return UserResource::collection(User::latest()->get());
    }
}
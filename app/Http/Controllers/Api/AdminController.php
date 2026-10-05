<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\SellerPayoutService;

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
            'total_boutiques' => Shop::count(),
            'boutiques_en_attente' => Shop::where('statut', 'en_attente')->count(),
            'total_produits' => Product::count(),
            'total_commandes' => Order::count(),
            // (float) : sum() sur une colonne decimal renvoie une chaîne via PDO,
            // ce qui cassait .toFixed() côté frontend.
            // Seules les commandes réellement payées (ni en attente, ni annulées/remboursées).
            'chiffre_affaires_total' => (float) Order::whereIn('statut', SellerPayoutService::PAID_STATUSES)->sum('total'),
            // Revenu de la plateforme : commissions figées au paiement.
            'commissions_total' => (float) OrderItem::whereHas(
                'order',
                fn ($q) => $q->whereIn('statut', SellerPayoutService::PAID_STATUSES)
            )->sum('commission_amount'),
        ]);
    }

    // GET /api/admin/users
    public function users()
    {
        return UserResource::collection(User::latest()->get());
    }
}
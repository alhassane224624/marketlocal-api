<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\SellerPayoutService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderController extends Controller
{
    /**
     * Cycle de vie d'une commande. « payee » n'apparaît jamais comme cible :
     * seul le webhook Stripe fait passer en_attente -> payee.
     * Annuler une commande déjà payée demandera un remboursement (étape « litiges »).
     */
    private const TRANSITIONS = [
        'en_attente' => ['annulee'],
        'payee' => ['expediee'],
        'expediee' => ['livree'],
        'livree' => [],
        'annulee' => [],
    ];

    // POST /api/orders (acheteur — créer une commande à partir du panier)
    public function store(StoreOrderRequest $request)
    {
        $buyerId = $request->user()->id;

        $order = DB::transaction(function () use ($request, $buyerId) {
            $total = 0;
            $itemsToCreate = [];

            foreach ($request->validated('items') as $item) {
                // Verrou : évite de vendre deux fois le même stock.
                $product = Product::with('shop:id,commission,statut')
                    ->whereHas('shop', fn ($q) => $q->where('statut', 'valide'))
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);

                if ($product->stock < $item['quantite']) {
                    abort(422, "Stock insuffisant pour le produit : {$product->nom}");
                }

                $total += (float) $product->prix * $item['quantite'];

                // On fige nom, boutique et commission au moment de la vente.
                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'shop_id' => $product->shop_id,
                    'product_nom' => $product->nom,
                    'quantite' => $item['quantite'],
                    'prix_unitaire' => $product->prix,
                    'taux_commission' => $product->shop?->commission,
                    'statut' => 'en_attente',
                ];

                $product->decrement('stock', $item['quantite']);
            }

            $buyer = $request->user();
            $livraison = $this->resolveDeliveryAddress($request, $buyer);

            $order = Order::create([
                'buyer_id' => $buyerId,
                'statut' => 'en_attente',
                'total' => round($total, 2),
                'adresse_livraison' => $livraison['adresse'],
                'ville_livraison' => $livraison['ville'],
                'telephone_livraison' => $livraison['telephone'],
            ]);

            foreach ($itemsToCreate as $itemData) {
                $order->items()->create($itemData);
            }

            return $order;
        });

        return response()->json(
            OrderResource::make($order->load('items'))->resolve(),
            201
        );
    }

    // GET /api/orders/mine (acheteur)
    public function mine(Request $request)
    {
        return OrderResource::collection(
            $request->user()
                ->orders()
                ->with('items.product')
                ->latest()
                ->get()
        );
    }

    // GET /api/orders/{order} (acheteur de la commande, vendeur concerné, ou admin)
    public function show(Request $request, Order $order)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';
        $isBuyer = (int) $order->buyer_id === (int) $user->id;
        $shopId = $user->shop?->id;

        $isVendorOfItem = $shopId !== null
            && $order->items()->where('shop_id', $shopId)->exists();

        if (! $isBuyer && ! $isVendorOfItem && ! $isAdmin) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        // Un vendeur ne voit que les articles de sa propre boutique.
        $restrictToShop = ! $isBuyer && ! $isAdmin;

        $order->load([
            'buyer:id,name,email',
            'items' => function ($query) use ($restrictToShop, $shopId) {
                if ($restrictToShop) {
                    $query->where('shop_id', $shopId);
                }
                $query->with('product');
            },
        ]);

        return response()->json(OrderResource::make($order)->resolve());
    }

    // GET /api/shop/orders (vendeur — commandes reçues)
    public function shopOrders(Request $request)
    {
        $shopId = $request->user()->shop?->id;

        if ($shopId === null) {
            return response()->json(['message' => "Vous n'avez pas de boutique"], 404);
        }

        $orders = Order::whereHas('items', fn ($q) => $q->where('shop_id', $shopId))
            ->with([
                'items' => fn ($q) => $q->where('shop_id', $shopId)->with('product'),
                'buyer:id,name,email',
            ])
            ->latest()
            ->get();

        return OrderResource::collection($orders);
    }

    // GET /api/shop/stats (vendeur)
    public function shopStats(Request $request)
    {
        $shop = $request->user()->shop;

        if (! $shop) {
            return response()->json(['message' => "Vous n'avez pas de boutique"], 404);
        }

        $shopId = $shop->id;
        $tauxActuel = (float) $shop->commission; // en pourcentage, ex: 10.00

        // Lignes de cette boutique (shop_id figé à la vente), commandes payées uniquement.
        $baseItems = fn () => OrderItem::where('shop_id', $shopId)
            ->whereHas('order', fn ($q) => $q->whereIn('statut', SellerPayoutService::PAID_STATUSES));

        $depuis30j = fn ($q) => $q->where('created_at', '>=', now()->subDays(30));

        // Net = ventes moins la commission figée sur chaque ligne
        // (taux actuel de la boutique en repli si une ligne n'en a pas).
        $brut = DB::raw('quantite * prix_unitaire');
        $net = DB::raw('quantite * prix_unitaire * (1 - COALESCE(taux_commission, ' . $tauxActuel . ') / 100.0)');

        $chiffreAffairesBrut = (float) $baseItems()->sum($brut);
        $chiffreAffairesNet = (float) $baseItems()->sum($net);
        $chiffreAffairesBrut30j = (float) $baseItems()->whereHas('order', $depuis30j)->sum($brut);
        $chiffreAffairesNet30j = (float) $baseItems()->whereHas('order', $depuis30j)->sum($net);

        $ordersOfShop = fn () => Order::whereHas('items', fn ($q) => $q->where('shop_id', $shopId));

        return response()->json([
            'produits_en_ligne' => Product::where('shop_id', $shopId)->count(),
            'produits_en_rupture' => Product::where('shop_id', $shopId)->where('stock', 0)->count(),
            'total_commandes' => $ordersOfShop()->count(),
            'commandes_en_cours' => $ordersOfShop()
                ->whereIn('statut', ['en_attente', 'payee', 'expediee'])
                ->count(),
            'chiffre_affaires_brut' => round($chiffreAffairesBrut, 2),
            'chiffre_affaires_net' => round($chiffreAffairesNet, 2),
            'chiffre_affaires_brut_30j' => round($chiffreAffairesBrut30j, 2),
            'chiffre_affaires_net_30j' => round($chiffreAffairesNet30j, 2),
            'taux_commission' => $tauxActuel,
        ]);
    }

    // PUT /api/orders/{order}/statut (vendeur ou admin)
    // Autorisation + statuts permis par rôle : UpdateOrderStatusRequest.
    // Admin : transitions sur la commande entière.
    // Vendeur : transitions sur les articles de SA boutique ; le statut global est
    // ensuite recalculé (une commande multi-vendeurs avance au rythme du plus lent).
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order, StripeConnectService $stripe)
    {
        $nouveau = $request->validated('statut');
        $isAdmin = $request->user()->role === 'admin';
        $shopId = $request->user()->shop?->id;

        $order = DB::transaction(function () use ($order, $nouveau, $isAdmin, $shopId, $stripe) {
            // Verrou : évite qu'un webhook Stripe et un changement de statut se marchent dessus.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $isAdmin) {
                return $this->advanceShopItems($locked, $shopId, $nouveau);
            }

            $autorises = self::TRANSITIONS[$locked->statut] ?? [];

            if (! in_array($nouveau, $autorises, true)) {
                abort(409, "Changement de statut impossible : {$locked->statut} -> {$nouveau}");
            }

            // Annulation d'une commande non payée : on bloque d'abord le paiement Stripe,
            // puis on remet le stock en vente.
            if ($nouveau === 'annulee') {
                if ($locked->stripe_payment_intent_id) {
                    try {
                        $cancelled = $stripe->cancelPaymentIntent($locked->stripe_payment_intent_id);
                    } catch (Throwable $e) {
                        report($e);
                        abort(502, "Impossible d'annuler le paiement Stripe, réessayez.");
                    }
                    if (! $cancelled) {
                        abort(409, 'Le paiement de cette commande est déjà en cours ou abouti.');
                    }
                }

                foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                    Product::whereKey($item->product_id)->increment('stock', $item->quantite);
                }
            }

            $locked->items()->update(['statut' => $nouveau]);
            $locked->update(['statut' => $nouveau]);

            return $locked;
        });

        return response()->json(OrderResource::make($order)->resolve());
    }

    private function advanceShopItems(Order $locked, ?int $shopId, string $nouveau): Order
    {
        $sellerItems = $locked->items()->where('shop_id', $shopId)->lockForUpdate()->get();
        if ($sellerItems->isEmpty()) {
            abort(403, 'Cette commande ne contient aucun article de votre boutique.');
        }

        $expectedCurrent = $nouveau === 'expediee' ? 'payee' : 'expediee';
        if ($sellerItems->contains(fn ($item) => $item->statut !== $expectedCurrent)) {
            abort(409, "Les articles de votre boutique ne peuvent pas passer directement à {$nouveau}.");
        }

        $locked->items()->where('shop_id', $shopId)->update(['statut' => $nouveau]);

        $allItems = $locked->items()->pluck('statut');
        $global = match (true) {
            $allItems->every(fn ($status) => $status === 'livree') => 'livree',
            $allItems->every(fn ($status) => in_array($status, ['expediee', 'livree'], true)) => 'expediee',
            default => 'payee', // partiellement expédiée
        };

        $locked->update(['statut' => $global]);

        return $locked;
    }

    // GET /api/admin/orders
    public function adminIndex()
    {
        return OrderResource::collection(
            Order::with(['items.product', 'buyer:id,name,email'])
                ->latest()
                ->get()
        );
    }

    /**
     * Détermine l'adresse de livraison à figer sur la commande, par ordre de priorité :
     * 1) une adresse enregistrée (address_id, doit appartenir à l'acheteur) ;
     * 2) une adresse ponctuelle envoyée dans la requête ;
     * 3) à défaut, l'adresse du profil (compatibilité avec l'existant).
     */
    private function resolveDeliveryAddress(StoreOrderRequest $request, $buyer): array
    {
        if ($request->filled('address_id')) {
            $address = $buyer->addresses()->find($request->input('address_id'));

            if (! $address) {
                abort(422, "Cette adresse ne vous appartient pas ou n'existe plus.");
            }

            return [
                'adresse' => $address->adresse,
                'ville' => $address->ville,
                'telephone' => $address->telephone,
            ];
        }

        return [
            'adresse' => $request->input('adresse_livraison') ?: $buyer->adresse,
            'ville' => $request->input('ville_livraison') ?: $buyer->ville,
            'telephone' => $request->input('telephone_livraison') ?: $buyer->telephone,
        ];
    }
}

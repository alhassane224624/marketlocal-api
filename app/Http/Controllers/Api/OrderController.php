<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\AdminLog;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Notifications\OrderDelivered;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Cycle de vie d'une commande payée par carte. « payee » n'apparaît jamais comme cible :
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

    /**
     * Cycle de vie d'une commande payée à la livraison : pas d'étape « payee ».
     * Le vendeur expédie directement ; l'encaissement a lieu à la livraison (livree).
     * Un colis refusé à la livraison peut être annulé par l'admin (stock restitué).
     */
    private const TRANSITIONS_LIVRAISON = [
        'en_attente' => ['expediee', 'annulee'],
        'expediee' => ['livree', 'annulee'],
        'livree' => [],
        'annulee' => [],
    ];

    /** Livraisons d'une commande, avec ce qu'il faut pour DeliveryResource. */
    private const DELIVERY_RELATIONS = [
        'deliveries.order.items',
        'deliveries.order.buyer:id,name',
        'deliveries.shop.user:id,adresse,ville,telephone',
        'deliveries.livreur:id,name,telephone',
    ];

    // POST /api/orders (acheteur — créer une commande à partir du panier)
    public function store(StoreOrderRequest $request)
    {
        $buyerId = $request->user()->id;

        $order = DB::transaction(function () use ($request, $buyerId) {
            $total = 0;
            $itemsToCreate = [];
            $modePaiement = $request->validated('mode_paiement') ?? Order::PAIEMENT_CARTE;

            // Verrous toujours pris dans le même ordre (par id) : deux paniers contenant les mêmes
            // produits dans un ordre différent ne peuvent pas se bloquer mutuellement (deadlock).
            $items = collect($request->validated('items'))->sortBy('product_id');

            foreach ($items as $item) {
                // Verrou : évite de vendre deux fois le même stock.
                $product = Product::with('shop:id,nom,commission,statut,stripe_account_id,is_active')
                    ->visible()
                    ->lockForUpdate()
                    ->find($item['product_id']);

                if (! $product) {
                    abort(422, "Un produit de votre panier n'est plus disponible.");
                }

                if ($product->stock < $item['quantite']) {
                    abort(422, "Stock insuffisant pour le produit : {$product->nom}");
                }

                // Paiement carte avec Stripe Connect obligatoire : on refuse tout de suite plutôt que
                // de bloquer le stock 30 min pour un paiement qui sera refusé (createPaymentIntent).
                if ($modePaiement === Order::PAIEMENT_CARTE
                    && config('services.stripe.connect_required')
                    && (! $product->shop?->stripe_account_id || ! $product->shop?->is_active)) {
                    abort(422, "La boutique {$product->shop?->nom} n'accepte pas encore le paiement par carte. Choisissez le paiement à la livraison.");
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

            // Frais de livraison : un colis par boutique de la commande.
            $fraisColis = Order::fraisParColis();
            $nbColis = collect($itemsToCreate)->pluck('shop_id')->unique()->count();
            $fraisLivraison = round($fraisColis * $nbColis, 2);
            $total += $fraisLivraison;

            if ($modePaiement === Order::PAIEMENT_LIVRAISON) {
                $this->ensureCashOnDeliveryAllowed($total);
            }

            $order = Order::create([
                'buyer_id' => $buyerId,
                'statut' => 'en_attente',
                'total' => round($total, 2), // articles + frais de livraison
                'frais_livraison' => $fraisLivraison,
                'frais_livraison_colis' => $fraisColis,
                'mode_paiement' => $modePaiement,
                'adresse_livraison' => $livraison['adresse'],
                'ville_livraison' => $livraison['ville'],
                'telephone_livraison' => $livraison['telephone'],
            ]);

            foreach ($itemsToCreate as $itemData) {
                $order->items()->create($itemData);
            }

            return $order;
        });

        // Paiement carte : les e-mails partent une fois le paiement reçu (PaymentController).
        if ($order->isCashOnDelivery()) {
            $order->sendConfirmationMails();
        }

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
                ->with(['items.product', ...self::DELIVERY_RELATIONS])
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
            'deliveries' => function ($query) use ($restrictToShop, $shopId) {
                if ($restrictToShop) {
                    $query->where('shop_id', $shopId);
                }
                $query->with(['shop.user:id,adresse,ville,telephone', 'livreur:id,name,telephone']);
            },
            'deliveries.order.items',
            'deliveries.order.buyer:id,name',
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
                'deliveries' => fn ($q) => $q->where('shop_id', $shopId)
                    ->with(['order.items', 'order.buyer:id,name', 'shop.user:id,adresse,ville,telephone', 'livreur:id,name,telephone']),
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

        // Lignes de cette boutique (shop_id figé à la vente) réellement encaissées :
        // carte payée (et non remboursée), ou paiement à la livraison une fois livré.
        // Avant, les commandes carte non payées (en_attente) gonflaient le chiffre d'affaires.
        $baseItems = fn () => OrderItem::where('shop_id', $shopId)->encaissees();

        $depuis30j = fn ($q) => $q->where('created_at', '>=', now()->subDays(30));

        // Net = ventes moins la commission figée sur chaque ligne
        // (taux actuel de la boutique en repli si une ligne n'en a pas).
        $brut = DB::raw('quantite * prix_unitaire');
        $net = DB::raw('quantite * prix_unitaire * (1 - COALESCE(taux_commission, ' . $tauxActuel . ') / 100.0)');

        $chiffreAffairesBrut = (float) $baseItems()->sum($brut);
        $chiffreAffairesNet = (float) $baseItems()->sum($net);
        $chiffreAffairesBrut30j = (float) $baseItems()->whereHas('order', $depuis30j)->sum($brut);
        $chiffreAffairesNet30j = (float) $baseItems()->whereHas('order', $depuis30j)->sum($net);

        // Où en est l'argent du vendeur (lignes encaissées, commission déduite) :
        //  - verse : virements Stripe effectués ;
        //  - en_attente : virements Stripe à venir (ou bloqués par un litige) ;
        //  - especes : encaissé directement par le vendeur (paiement à la livraison),
        //    dont la commission reste due à la plateforme (commission_especes).
        $finances = ['verse' => 0.0, 'en_attente' => 0.0, 'especes' => 0.0, 'commission_especes' => 0.0];
        foreach ($baseItems()->get(['quantite', 'prix_unitaire', 'taux_commission', 'commission_amount', 'seller_amount', 'seller_transfer_status']) as $item) {
            $gross = (float) $item->prix_unitaire * (int) $item->quantite;
            $commission = $item->commission_amount !== null
                ? (float) $item->commission_amount
                : $gross * (float) ($item->taux_commission ?? $tauxActuel) / 100;
            $net = $item->seller_amount !== null ? (float) $item->seller_amount : $gross - $commission;

            match ($item->seller_transfer_status) {
                'completed' => $finances['verse'] += $net,
                'cash_collected' => [$finances['especes'] += $net, $finances['commission_especes'] += $commission],
                default => $finances['en_attente'] += $net,
            };
        }
        $finances = array_map(fn ($v) => round($v, 2), $finances);

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
            'finances' => $finances,
        ]);
    }

    // PUT /api/orders/{order}/statut (vendeur ou admin)
    // Autorisation + statuts permis par rôle : UpdateOrderStatusRequest.
    // Ici : les transitions autorisées.
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order)
    {
        $nouveau = $request->validated('statut');

        $order = DB::transaction(function () use ($request, $order, $nouveau) {
            // Verrou : évite qu'un webhook Stripe et un changement de statut se marchent dessus.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $cod = $locked->isCashOnDelivery();

            if ($request->user()->role === 'admin') {
                // L'admin agit sur toute la commande : on contrôle le statut de la commande.
                $autorises = ($cod ? self::TRANSITIONS_LIVRAISON : self::TRANSITIONS)[$locked->statut] ?? [];
                if (! in_array($nouveau, $autorises, true)) {
                    abort(409, "Changement de statut impossible : {$locked->statut} -> {$nouveau}");
                }
            } elseif (in_array($locked->statut, ['annulee', 'livree'], true)) {
                // Le vendeur n'agit que sur ses articles (contrôlés plus bas) : dans une commande
                // multi-vendeurs, la commande peut être « payee » alors que ses articles sont déjà expédiés.
                abort(409, "Changement de statut impossible : la commande est {$locked->statut}.");
            }

            // Un livreur s'occupe de ce colis : c'est lui qui le fait avancer (récupéré, livré).
            if ($request->user()->role !== 'admin' && $nouveau !== 'annulee') {
                $courseActive = Delivery::where('order_id', $locked->id)
                    ->where('shop_id', $request->user()->shop?->id)
                    ->whereIn('statut', Delivery::ACTIVES)
                    ->exists();
                if ($courseActive) {
                    abort(409, 'Un livreur est chargé de ce colis : son statut avance avec la course.');
                }
            }

            // Annulation : les courses pas encore livrées sont annulées aussi.
            if ($nouveau === 'annulee') {
                Delivery::where('order_id', $locked->id)->whereIn('statut', Delivery::ACTIVES)->update(['statut' => 'annulee']);
            }

            // Annulation d'une commande non payée : on remet le stock en vente.
            if ($nouveau === 'annulee') {
                foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                    Product::whereKey($item->product_id)->increment('stock', $item->quantite);
                }
            }

            if ($nouveau === 'annulee') {
                $locked->items()->update(['statut' => 'annulee']);
            } elseif ($request->user()->role === 'admin') {
                $locked->items()->update(['statut' => $nouveau]);
            } else {
                $shopId = $request->user()->shop?->id;
                $sellerItems = $locked->items()->where('shop_id', $shopId)->lockForUpdate()->get();
                if ($sellerItems->isEmpty()) {
                    abort(403, 'Cette commande ne contient aucun article de votre boutique.');
                }
                $expectedCurrent = $nouveau === 'expediee' ? ($cod ? 'en_attente' : 'payee') : 'expediee';
                if ($sellerItems->contains(fn ($item) => $item->statut !== $expectedCurrent)) {
                    abort(409, "Les articles de votre boutique ne peuvent pas passer directement à {$nouveau}.");
                }
                $locked->items()->where('shop_id', $shopId)->update(['statut' => $nouveau]);

                // Paiement à la livraison : ce vendeur a encaissé sa part dès qu'il a livré,
                // même si les autres vendeurs de la commande n'ont pas encore livré.
                if ($cod && $nouveau === 'livree') {
                    foreach ($locked->items()->where('shop_id', $shopId)->with('shop')->get() as $item) {
                        $item->settleAmounts('cash_collected');
                    }
                }

                $allItems = $locked->items()->pluck('statut');
                if ($allItems->every(fn ($status) => $status === 'livree')) {
                    $nouveau = 'livree';
                } elseif ($allItems->every(fn ($status) => in_array($status, ['expediee', 'livree'], true))) {
                    $nouveau = 'expediee';
                } else {
                    // Une commande multi-vendeurs peut être partiellement expédiée.
                    $locked->update(['statut' => $cod ? 'en_attente' : 'payee']);
                    return $locked;
                }
            }

            $locked->update(['statut' => $nouveau]);

            // Paiement à la livraison : l'argent est encaissé à la remise du colis
            // (lignes pas encore réglées : livraison par l'admin, ou dernier vendeur).
            if ($cod && $nouveau === 'livree') {
                foreach ($locked->items()->where('seller_transfer_status', '!=', 'cash_collected')->with('shop')->get() as $item) {
                    $item->settleAmounts('cash_collected');
                }
                $locked->update(['paid_at' => now()]);
            }

            return $locked;
        });

        $demande = $request->validated('statut');
        $isAdmin = $request->user()->role === 'admin';

        if ($demande === 'livree') {
            // Vendeur : seuls ses articles sont livrés. Admin : toute la commande.
            Notifier::send($order->buyer, new OrderDelivered($order, $isAdmin ? null : $request->user()->shop?->id));
        }
        if ($isAdmin) {
            AdminLog::record('commande_statut', $order, "Commande n°{$order->id} passée à « {$demande} »");
        }

        return response()->json(OrderResource::make($order)->resolve());
    }

    // GET /api/admin/orders
    public function adminIndex()
    {
        return OrderResource::collection(
            Order::with(['items.product', 'buyer:id,name,email', ...self::DELIVERY_RELATIONS])
                ->latest()
                ->get()
        );
    }

    private function ensureCashOnDeliveryAllowed(float $total): void
    {
        if (! config('shop.cash_on_delivery.enabled', true)) {
            abort(422, "Le paiement à la livraison n'est pas disponible pour le moment.");
        }

        $max = (float) config('shop.cash_on_delivery.max_total', 0);
        if ($max > 0 && $total > $max) {
            abort(422, 'Le paiement à la livraison est limité aux commandes de '.number_format($max, 2, ',', ' ').' MAD maximum.');
        }
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

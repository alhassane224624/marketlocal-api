<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_fake',
        'services.stripe.webhook_secret' => 'whsec_test',
    ]);
});

function makeUser(string $role, string $email): User
{
    return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('password123'), 'role' => $role]);
}

function makeShop(string $email, array $attributes = []): Shop
{
    return Shop::create(array_merge([
        'user_id' => makeUser('vendeur', $email)->id,
        'nom' => 'Boutique '.$email,
        'statut' => 'valide',
        'commission' => 10,
        'stripe_account_id' => 'acct_'.md5($email),
        'is_active' => true,
    ], $attributes));
}

function makeProduct(Shop $shop, float $prix = 100, int $stock = 10): Product
{
    $category = Category::firstOrCreate(['nom' => 'Test']);

    return Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Produit '.$shop->id, 'prix' => $prix, 'stock' => $stock]);
}

/** Commande créée par l'API (même chemin que le frontend). */
function placeOrder(User $buyer, array $lines): Order
{
    $items = collect($lines)->map(fn ($qty, $productId) => ['product_id' => $productId, 'quantite' => $qty])->values()->all();

    $response = test()->actingAs($buyer, 'sanctum')->postJson('/api/orders', [
        'items' => $items,
        'adresse_livraison' => '1 rue Test',
        'ville_livraison' => 'Casablanca',
    ])->assertCreated();

    return Order::findOrFail($response->json('id'));
}

/** Envoie un événement payment_intent.succeeded signé comme le ferait Stripe. */
function sendPaymentSucceeded(Order $order, int $amountCents)
{
    $payload = json_encode([
        'id' => 'evt_'.uniqid(),
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_order_'.$order->id,
            'object' => 'payment_intent',
            'amount_received' => $amountCents,
            'latest_charge' => 'ch_order_'.$order->id,
            'metadata' => ['order_id' => (string) $order->id],
        ]],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

    return test()->call('POST', '/api/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}

test('settle-pending ne verse rien pour une commande non payée', function () {
    $shop = makeShop('v@example.com');
    $product = makeProduct($shop);
    $order = placeOrder(makeUser('acheteur', 'a@example.com'), [$product->id => 2]);

    $stripe = $this->mock(StripeConnectService::class);
    $stripe->shouldNotReceive('transfer');

    $this->actingAs($shop->user, 'sanctum')
        ->postJson('/api/shop/stripe/settle-pending')
        ->assertOk()
        ->assertJson(['transferts_effectues' => 0]);

    // Aucune tentative de versement : la ligne n'a jamais été « à verser ».
    expect($order->items()->first()->seller_transfer_status)->toBeNull();
});

test('le webhook marque la commande payée et verse la part vendeur une seule fois', function () {
    $shopA = makeShop('a-shop@example.com', ['commission' => 10]);
    $shopB = makeShop('b-shop@example.com', ['commission' => 20]);
    $order = placeOrder(makeUser('acheteur', 'buyer@example.com'), [
        makeProduct($shopA, 100)->id => 1,
        makeProduct($shopB, 50)->id => 2,
    ]);

    $stripe = $this->mock(StripeConnectService::class);
    // A : 100 - 10 % = 90 ; B : 100 - 20 % = 80. Rattachés au paiement (source_transaction).
    $stripe->shouldReceive('transfer')->once()
        ->withArgs(fn ($amount, $shop, $key, $orderId, $charge) => $amount === 9000 && $shop->is($shopA) && $charge === 'ch_order_'.$order->id)
        ->andReturn('tr_a');
    $stripe->shouldReceive('transfer')->once()
        ->withArgs(fn ($amount, $shop) => $amount === 8000 && $shop->is($shopB))
        ->andReturn('tr_b');

    sendPaymentSucceeded($order, 20000)->assertOk();
    // Stripe peut renvoyer le même événement : aucun nouveau transfert.
    sendPaymentSucceeded($order, 20000)->assertOk();

    $order->refresh();
    expect($order->statut)->toBe('payee')
        ->and($order->stripe_charge_id)->toBe('ch_order_'.$order->id)
        ->and($order->items->pluck('seller_transfer_status')->unique()->all())->toBe(['completed']);
});

test('une part bloquée est versée par settle-pending une fois le vendeur activé', function () {
    $shop = makeShop('late@example.com', ['is_active' => false]);
    $order = placeOrder(makeUser('acheteur', 'b2@example.com'), [makeProduct($shop, 100)->id => 1]);

    $stripe = $this->mock(StripeConnectService::class);
    sendPaymentSucceeded($order, 10000)->assertOk();
    expect($order->items()->first()->seller_transfer_status)->toBe('blocked');

    $shop->update(['is_active' => true]);
    $stripe->shouldReceive('transfer')->once()
        ->withArgs(fn ($amount) => $amount === 9000)
        ->andReturn('tr_late');

    $this->actingAs($shop->user, 'sanctum')
        ->postJson('/api/shop/stripe/settle-pending')
        ->assertOk()
        ->assertJson(['transferts_effectues' => 1]);
});

test('un paiement reçu sur une commande annulée est remboursé', function () {
    $shop = makeShop('c@example.com');
    $product = makeProduct($shop, 100, 5);
    $order = placeOrder(makeUser('acheteur', 'b3@example.com'), [$product->id => 1]);
    $order->update(['statut' => 'annulee']);
    $order->items()->update(['statut' => 'annulee']);

    $stripe = $this->mock(StripeConnectService::class);
    $stripe->shouldNotReceive('transfer');
    $stripe->shouldReceive('refund')->once()
        ->with('pi_order_'.$order->id, 10000, (string) $order->id)
        ->andReturn('re_late');

    sendPaymentSucceeded($order, 10000)->assertOk();

    $order->refresh();
    expect($order->statut)->toBe('annulee')
        ->and($order->stripe_refund_id)->toBe('re_late')
        ->and($order->refunded_at)->not->toBeNull();
});

test('le remboursement admin reprend les transferts déjà versés', function () {
    $shop = makeShop('d@example.com');
    $product = makeProduct($shop, 100, 5);
    $order = placeOrder(makeUser('acheteur', 'b4@example.com'), [$product->id => 1]);

    $stripe = $this->mock(StripeConnectService::class);
    $stripe->shouldReceive('transfer')->once()->andReturn('tr_paid');
    sendPaymentSucceeded($order, 10000)->assertOk();

    $stripe->shouldReceive('refund')->once()->andReturn('re_admin');
    $stripe->shouldReceive('reverseTransfer')->once()->with('tr_paid', 'marketlocal-reverse-tr_paid')->andReturn('trr_1');

    $this->actingAs(makeUser('admin', 'admin@example.com'), 'sanctum')
        ->postJson("/api/admin/orders/{$order->id}/rembourser")
        ->assertOk();

    $order->refresh();
    expect($order->statut)->toBe('annulee')
        ->and($order->items()->first()->seller_transfer_status)->toBe('reversed')
        ->and($product->fresh()->stock)->toBe(5);
});

test('l expiration annule le PaymentIntent, sauf si le paiement a abouti', function () {
    $shop = makeShop('e@example.com');
    $product = makeProduct($shop, 100, 10);
    $buyer = makeUser('acheteur', 'b5@example.com');
    $expired = placeOrder($buyer, [$product->id => 2]);
    $alreadyPaid = placeOrder($buyer, [$product->id => 3]);
    Order::query()->update(['created_at' => now()->subHour()]);
    $expired->update(['stripe_payment_intent_id' => 'pi_expired']);
    $alreadyPaid->update(['stripe_payment_intent_id' => 'pi_paid']);

    $stripe = $this->mock(StripeConnectService::class);
    $stripe->shouldReceive('cancelPaymentIntent')->with('pi_expired')->once()->andReturn(true);
    $stripe->shouldReceive('cancelPaymentIntent')->with('pi_paid')->once()->andReturn(false);

    Artisan::call('orders:cancel-expired');

    expect($expired->fresh()->statut)->toBe('annulee')
        ->and($alreadyPaid->fresh()->statut)->toBe('en_attente')
        ->and($product->fresh()->stock)->toBe(7);
});

test('un vendeur peut livrer ses articles même si l autre vendeur n a pas expédié', function () {
    $shopA = makeShop('fa@example.com');
    $shopB = makeShop('fb@example.com');
    $order = placeOrder(makeUser('acheteur', 'b6@example.com'), [
        makeProduct($shopA)->id => 1,
        makeProduct($shopB)->id => 1,
    ]);
    $order->update(['statut' => 'payee']);
    $order->items()->update(['statut' => 'payee']);

    $this->actingAs($shopA->user, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'expediee'])->assertOk();
    $this->actingAs($shopA->user, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'livree'])->assertOk();
    expect($order->fresh()->statut)->toBe('payee');

    $this->actingAs($shopB->user, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'expediee'])->assertOk();
    expect($order->fresh()->statut)->toBe('expediee');

    $this->actingAs($shopB->user, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'livree'])->assertOk();
    expect($order->fresh()->statut)->toBe('livree');
});

test('le chiffre d affaires admin ne compte que les commandes payées', function () {
    $shop = makeShop('g@example.com');
    $product = makeProduct($shop, 100);
    $buyer = makeUser('acheteur', 'b7@example.com');
    placeOrder($buyer, [$product->id => 1]); // reste en attente
    $paid = placeOrder($buyer, [$product->id => 2]);

    $this->mock(StripeConnectService::class)->shouldReceive('transfer')->andReturn('tr_g');
    sendPaymentSucceeded($paid, 20000)->assertOk();

    $this->actingAs(makeUser('admin', 'admin2@example.com'), 'sanctum')
        ->getJson('/api/admin/stats')
        ->assertOk()
        ->assertJson(['chiffre_affaires_total' => 200, 'commissions_total' => 20]);
});

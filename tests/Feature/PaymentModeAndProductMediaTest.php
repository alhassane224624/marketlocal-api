<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function marketplace(): array
{
    $vendeur = User::create(['name' => 'V', 'email' => 'v@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $acheteur = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $admin = User::create(['name' => 'Ad', 'email' => 'ad@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Artisanat']);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Panier', 'prix' => 100, 'stock' => 5]);

    return compact('vendeur', 'acheteur', 'admin', 'shop', 'category', 'product');
}

function orderPayload(Product $product, array $extra = []): array
{
    return array_merge([
        'items' => [['product_id' => $product->id, 'quantite' => 2]],
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ], $extra);
}

test('une commande à la livraison suit le cycle expédiée -> livrée et est encaissée à la livraison', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'product' => $product] = marketplace();

    $res = $this->actingAs($acheteur, 'sanctum')
        ->postJson('/api/orders', orderPayload($product, ['mode_paiement' => 'livraison']))
        ->assertCreated()
        ->assertJsonPath('mode_paiement', 'livraison');
    $order = Order::find($res->json('id'));

    // Pas de paiement Stripe possible pour ce mode.
    $this->actingAs($acheteur, 'sanctum')->postJson("/api/orders/{$order->id}/payer")->assertStatus(409);

    // Pas d'expiration automatique.
    $this->travel(2)->hours();
    $this->artisan('orders:cancel-expired')->assertSuccessful();
    expect($order->fresh()->statut)->toBe('en_attente');

    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'expediee'])
        ->assertOk()->assertJsonPath('statut', 'expediee');
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$order->id}/statut", ['statut' => 'livree'])
        ->assertOk()->assertJsonPath('statut', 'livree');

    $order->refresh();
    $item = $order->items()->first();
    expect($order->paid_at)->not->toBeNull()
        ->and((float) $item->commission_amount)->toBe(20.0)
        ->and((float) $item->seller_amount)->toBe(180.0)
        ->and($item->seller_transfer_status)->toBe('cash_collected');
});

test('une commande carte non payée ne peut pas être expédiée et expire', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'product' => $product] = marketplace();

    $id = $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', orderPayload($product))
        ->assertCreated()->assertJsonPath('mode_paiement', 'carte')->json('id');

    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'expediee'])->assertStatus(409);

    $this->travel(2)->hours();
    $this->artisan('orders:cancel-expired')->assertSuccessful();
    expect(Order::find($id)->statut)->toBe('annulee')
        ->and($product->fresh()->stock)->toBe(5);
});

test('le paiement à la livraison respecte le plafond configuré', function () {
    ['acheteur' => $acheteur, 'product' => $product] = marketplace();
    config(['shop.cash_on_delivery.max_total' => 150]);

    $this->actingAs($acheteur, 'sanctum')
        ->postJson('/api/orders', orderPayload($product, ['mode_paiement' => 'livraison']))
        ->assertStatus(422);
    expect($product->fresh()->stock)->toBe(5);
});

test('un colis refusé à la livraison peut être annulé par l admin avec restitution du stock', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'admin' => $admin, 'product' => $product] = marketplace();
    $id = $this->actingAs($acheteur, 'sanctum')
        ->postJson('/api/orders', orderPayload($product, ['mode_paiement' => 'livraison']))->json('id');
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'expediee'])->assertOk();

    $this->actingAs($admin, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'annulee'])->assertOk();
    expect($product->fresh()->stock)->toBe(5);
});

test('un produit peut avoir plusieurs images et plusieurs catégories', function () {
    Storage::fake('public');
    ['vendeur' => $vendeur, 'category' => $artisanat] = marketplace();
    $deco = Category::create(['nom' => 'Décoration']);

    $res = $this->actingAs($vendeur, 'sanctum')->post('/api/products', [
        'nom' => 'Lanterne', 'prix' => 320, 'stock' => 3,
        'category_ids' => [$deco->id, $artisanat->id],
        'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($res->json('images'))->toHaveCount(3)
        ->and(collect($res->json('categories'))->pluck('id')->all())->toBe([$deco->id, $artisanat->id])
        ->and($res->json('category_id'))->toBe($deco->id)
        ->and($res->json('image'))->toBe($res->json('images.0.url'));

    $productId = $res->json('id');
    [$first, $second, $third] = collect($res->json('images'))->pluck('id')->all();

    // Supprimer la 1re image, mettre la 3e en couverture, ajouter une image, changer les catégories.
    $res = $this->actingAs($vendeur, 'sanctum')->post("/api/products/{$productId}", [
        '_method' => 'PUT',
        'remove_image_ids' => [$first],
        'cover_image_id' => $third,
        'images' => [UploadedFile::fake()->image('d.jpg')],
        'category_ids' => [$artisanat->id],
    ], ['Accept' => 'application/json'])->assertOk();

    expect(collect($res->json('images'))->pluck('id')->take(2)->all())->toBe([$third, $second])
        ->and($res->json('images'))->toHaveCount(3)
        ->and($res->json('category_id'))->toBe($artisanat->id)
        ->and($res->json('categories'))->toHaveCount(1);

    // Le filtre catalogue tient compte de toutes les catégories du produit.
    $this->getJson('/api/products?category_id='.$artisanat->id)->assertJsonCount(2, 'data');
    $this->getJson('/api/products?category_id='.$deco->id)->assertJsonCount(0, 'data');

    // Une catégorie utilisée ne peut pas être supprimée.
    $admin = User::where('role', 'admin')->first();
    $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/categories/'.$artisanat->id)->assertStatus(409);
});

test('le nombre d images par produit est limité', function () {
    Storage::fake('public');
    ['vendeur' => $vendeur, 'category' => $category] = marketplace();

    $this->actingAs($vendeur, 'sanctum')->post('/api/products', [
        'nom' => 'Trop', 'prix' => 10, 'stock' => 1, 'category_ids' => [$category->id],
        'images' => collect(range(1, Product::MAX_IMAGES + 1))->map(fn ($i) => UploadedFile::fake()->image("$i.jpg"))->all(),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('commander un produit d une boutique non validée renvoie un message clair', function () {
    ['acheteur' => $acheteur, 'shop' => $shop, 'product' => $product] = marketplace();
    $shop->update(['statut' => 'refuse']);

    $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', orderPayload($product))
        ->assertStatus(422)
        ->assertJsonPath('message', "Un produit de votre panier n'est plus disponible.");
});

test('l adresse par défaut ne peut pas perdre son statut sans remplaçante', function () {
    ['acheteur' => $acheteur] = marketplace();
    $address = $acheteur->addresses()->create([
        'nom_destinataire' => 'A', 'telephone' => '0600000000', 'adresse' => 'Rue A', 'ville' => 'Nador', 'est_par_defaut' => true,
    ]);

    $this->actingAs($acheteur, 'sanctum')->putJson('/api/addresses/'.$address->id, [
        'nom_destinataire' => 'A', 'telephone' => '0600000000', 'adresse' => 'Rue B', 'ville' => 'Nador', 'est_par_defaut' => false,
    ])->assertOk();

    expect($address->fresh()->est_par_defaut)->toBeTrue()
        ->and($address->fresh()->adresse)->toBe('Rue B');
});

test('commande à la livraison multi-vendeurs : chaque vendeur est encaissé à sa propre livraison', function () {
    ['vendeur' => $vendeurA, 'acheteur' => $acheteur, 'category' => $category, 'product' => $productA] = marketplace();
    $vendeurB = User::create(['name' => 'B', 'email' => 'b2@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $shopB = Shop::create(['user_id' => $vendeurB->id, 'nom' => 'Shop B', 'statut' => 'valide', 'commission' => 10]);
    $productB = Product::create(['shop_id' => $shopB->id, 'category_id' => $category->id, 'nom' => 'Tapis', 'prix' => 50, 'stock' => 5]);

    $id = $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', orderPayload($productA, [
        'mode_paiement' => 'livraison',
        'items' => [['product_id' => $productA->id, 'quantite' => 1], ['product_id' => $productB->id, 'quantite' => 1]],
    ]))->assertCreated()->json('id');

    $this->actingAs($vendeurA, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'expediee'])->assertOk();
    $this->actingAs($vendeurA, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'livree'])->assertOk();

    $order = Order::find($id);
    $itemA = $order->items()->where('shop_id', $productA->shop_id)->first();
    $itemB = $order->items()->where('shop_id', $shopB->id)->first();
    expect($order->statut)->toBe('en_attente')
        ->and($order->paid_at)->toBeNull()
        ->and($itemA->seller_transfer_status)->toBe('cash_collected')
        ->and($itemB->statut)->toBe('en_attente');

    $this->actingAs($vendeurB, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'expediee'])->assertOk();
    $this->actingAs($vendeurB, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'livree'])
        ->assertOk()->assertJsonPath('statut', 'livree');

    $order->refresh();
    expect($order->paid_at)->not->toBeNull()
        ->and($order->items()->where('seller_transfer_status', 'cash_collected')->count())->toBe(2);
});

test('les statistiques ne comptent que l argent réellement encaissé', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'admin' => $admin, 'product' => $product] = marketplace();

    // Commande carte jamais payée : ne doit pas compter.
    $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', orderPayload($product))->assertCreated();

    // Commande à la livraison livrée : compte (2 x 100, commission 10 %).
    $id = $this->actingAs($acheteur, 'sanctum')
        ->postJson('/api/orders', orderPayload($product, ['mode_paiement' => 'livraison']))->json('id');
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'expediee'])->assertOk();
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$id}/statut", ['statut' => 'livree'])->assertOk();

    // JSON envoie 200.0 sous la forme 200 : on compare la valeur numérique.
    $shop = $this->actingAs($vendeur, 'sanctum')->getJson('/api/shop/stats')->assertOk();
    expect((float) $shop->json('chiffre_affaires_brut'))->toBe(200.0)
        ->and((float) $shop->json('chiffre_affaires_net'))->toBe(180.0)
        // Paiement à la livraison : le vendeur a encaissé 180 en espèces, 20 de commission dus.
        ->and((float) $shop->json('finances.especes'))->toBe(180.0)
        ->and((float) $shop->json('finances.commission_especes'))->toBe(20.0)
        ->and((float) $shop->json('finances.verse'))->toBe(0.0)
        ->and((float) $shop->json('finances.en_attente'))->toBe(0.0);

    $stats = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/stats')->assertOk();
    expect((float) $stats->json('chiffre_affaires_total'))->toBe(200.0)
        ->and((float) $stats->json('commissions_total'))->toBe(20.0);
});

test('le catalogue renvoie la note moyenne et le nombre d avis', function () {
    ['acheteur' => $acheteur, 'product' => $product] = marketplace();
    \App\Models\Review::create(['product_id' => $product->id, 'user_id' => $acheteur->id, 'note' => 4]);

    $liste = $this->getJson('/api/products')->assertJsonPath('data.0.nombre_avis', 1);
    expect((float) $liste->json('data.0.note_moyenne'))->toBe(4.0);
    $this->getJson('/api/products/'.$product->id)
        ->assertJsonPath('reviews.0.user.name', 'A');
});

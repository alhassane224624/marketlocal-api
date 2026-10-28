<?php

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function livraisonSetup($test): array
{
    config(['shop.delivery.fee_per_shop' => 20, 'shop.delivery.livreur_share' => 80]);

    $vendeur = User::create(['name' => 'V', 'email' => 'v@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur', 'ville' => 'Nador']);
    $acheteur = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $livreur = User::create(['name' => 'L', 'email' => 'l@example.com', 'password' => Hash::make('password123'), 'role' => 'livreur', 'telephone' => '0600000001']);
    $autre = User::create(['name' => 'L2', 'email' => 'l2@example.com', 'password' => Hash::make('password123'), 'role' => 'livreur', 'telephone' => '0600000002']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Artisanat']);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Panier', 'prix' => 100, 'stock' => 5]);

    $orderId = $test->actingAs($acheteur, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 2]],
        'mode_paiement' => 'livraison',
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ])->assertCreated()->json('id');

    return compact('vendeur', 'acheteur', 'livreur', 'autre', 'orderId');
}

test('une course suit le cycle disponible -> acceptée -> en route -> livrée avec le code', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'livreur' => $livreur, 'autre' => $autre, 'orderId' => $orderId] = livraisonSetup($this);

    $deliveryId = $this->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")
        ->assertCreated()->assertJsonPath('statut', 'disponible')->assertJsonPath('code', null)->json('id');

    // Avant acceptation, le livreur ne voit pas l'adresse exacte.
    $dispo = $this->actingAs($livreur, 'sanctum')->getJson('/api/livraisons/disponibles')
        ->assertJsonPath('0.id', $deliveryId)
        ->assertJsonPath('0.destination.ville', 'Nador')
        ->assertJsonPath('0.destination.adresse', null);
    // Articles 200 + frais 20 ; le livreur voit sa rémunération (80 % de 20) avant d'accepter.
    // JSON envoie 220.0 sous la forme 220 : on compare la valeur numérique.
    expect((float) $dispo->json('0.a_encaisser'))->toBe(220.0)
        ->and((float) $dispo->json('0.remuneration_livreur'))->toBe(16.0);

    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$deliveryId}/accepter")
        ->assertOk()->assertJsonPath('destination.adresse', '1 rue Test');
    // Un second livreur ne peut plus la prendre.
    $this->actingAs($autre, 'sanctum')->postJson("/api/livraisons/{$deliveryId}/accepter")->assertStatus(409);
    // Le vendeur ne peut plus faire avancer le colis lui-même.
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$orderId}/statut", ['statut' => 'expediee'])->assertStatus(409);

    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$deliveryId}/recuperer")->assertOk();
    expect(Order::find($orderId)->statut)->toBe('expediee');

    // L'acheteur voit le code ; un mauvais code est refusé.
    $code = $this->actingAs($acheteur, 'sanctum')->getJson("/api/orders/{$orderId}")->json('deliveries.0.code');
    expect($code)->toHaveLength(4);
    $mauvais = $code === '0000' ? '1111' : '0000';
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$deliveryId}/livrer", ['code' => $mauvais])->assertStatus(422);

    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$deliveryId}/livrer", ['code' => $code])
        ->assertOk()->assertJsonPath('statut', 'livree');

    $order = Order::find($orderId);
    expect($order->statut)->toBe('livree')
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->items()->first()->seller_transfer_status)->toBe('cash_collected');
});

test('un livreur peut abandonner une course avant de récupérer le colis', function () {
    ['vendeur' => $vendeur, 'livreur' => $livreur, 'orderId' => $orderId] = livraisonSetup($this);
    $id = $this->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")->json('id');

    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/accepter")->assertOk();
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/abandonner")->assertOk();

    expect(Delivery::find($id)->statut)->toBe('disponible')
        ->and(Delivery::find($id)->livreur_id)->toBeNull();
});

test('annuler la commande annule la course', function () {
    ['vendeur' => $vendeur, 'orderId' => $orderId] = livraisonSetup($this);
    $admin = User::create(['name' => 'Ad', 'email' => 'ad@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $id = $this->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")->json('id');

    $this->actingAs($admin, 'sanctum')->putJson("/api/orders/{$orderId}/statut", ['statut' => 'annulee'])->assertOk();
    expect(Delivery::find($id)->statut)->toBe('annulee');
});

test('seuls les livreurs accèdent aux courses', function () {
    ['acheteur' => $acheteur] = livraisonSetup($this);
    $this->actingAs($acheteur, 'sanctum')->getJson('/api/livraisons/disponibles')->assertStatus(403);
});

test('la commande inclut les frais de livraison par boutique', function () {
    ['orderId' => $orderId] = livraisonSetup($this);
    $order = Order::find($orderId);

    expect((float) $order->frais_livraison)->toBe(20.0)
        ->and((float) $order->total)->toBe(220.0);
});

test('le livreur partage sa position, visible par l acheteur pendant la course', function () {
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'livreur' => $livreur, 'orderId' => $orderId] = livraisonSetup($this);
    $id = $this->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")->json('id');

    // Pas avant d'avoir accepté la course.
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/position", ['lat' => 35.17, 'lng' => -2.93])->assertStatus(403);

    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/accepter")->assertOk();
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/position", ['lat' => 35.17, 'lng' => -2.93])->assertNoContent();

    $position = $this->actingAs($acheteur, 'sanctum')->getJson("/api/orders/{$orderId}")->json('deliveries.0.position');
    expect((float) $position['lat'])->toBe(35.17);
});

test('la rémunération carte est due par la plateforme puis marquée versée par l admin', function () {
    config(['shop.delivery.fee_per_shop' => 20, 'shop.delivery.livreur_share' => 80]);
    $livreur = User::create(['name' => 'L', 'email' => 'lx@example.com', 'password' => Hash::make('password123'), 'role' => 'livreur', 'telephone' => '0600000009']);
    $admin = User::create(['name' => 'Ad', 'email' => 'adx@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $vendeur = User::create(['name' => 'V', 'email' => 'vx@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $acheteur = User::create(['name' => 'A', 'email' => 'ax@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'S', 'statut' => 'valide', 'commission' => 10]);
    $order = Order::create(['buyer_id' => $acheteur->id, 'statut' => 'livree', 'total' => 120, 'frais_livraison' => 20, 'frais_livraison_colis' => 20, 'mode_paiement' => 'carte']);
    Delivery::create(['order_id' => $order->id, 'shop_id' => $shop->id, 'livreur_id' => $livreur->id, 'statut' => 'livree', 'code' => '1234', 'frais' => 20, 'remuneration_livreur' => 16, 'delivered_at' => now(), 'recu_confirme_at' => now()]);

    expect((float) $this->actingAs($livreur, 'sanctum')->getJson('/api/livraisons/gains')->json('a_recevoir'))->toBe(16.0);

    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/livreurs/{$livreur->id}/payer")->assertOk();
    expect((float) $this->actingAs($livreur, 'sanctum')->getJson('/api/livraisons/gains')->json('a_recevoir'))->toBe(0.0);
});

/** Commande COD livrée par un livreur : renvoie [acheteur, livreur, id de la course]. */
function livraisonEffectuee($test): array
{
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'livreur' => $livreur, 'orderId' => $orderId] = livraisonSetup($test);
    $id = $test->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")->json('id');
    $test->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/accepter")->assertOk();
    $test->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/recuperer")->assertOk();
    $code = Delivery::find($id)->code;
    $test->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/livrer", ['code' => $code])->assertOk();

    return [$acheteur, $livreur, $id];
}

test('le client confirme la réception après la remise par le livreur', function () {
    [$acheteur, $livreur, $id] = livraisonEffectuee($this);

    // Le livreur ne peut pas confirmer à la place du client.
    $this->actingAs($livreur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/confirmer")->assertStatus(403);

    $this->actingAs($acheteur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/confirmer")->assertOk();
    expect(Delivery::find($id)->recu_confirme_at)->not->toBeNull();

    // Une seule réponse possible.
    $this->actingAs($acheteur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/signaler", ['message' => 'Colis abîmé'])->assertStatus(409);
});

test('un problème signalé bloque la rémunération et l auto-confirmation', function () {
    [$acheteur, , $id] = livraisonEffectuee($this);

    $this->actingAs($acheteur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/signaler", ['message' => 'Il manque un article'])->assertOk();

    $this->travel(3)->days();
    $this->artisan('deliveries:auto-confirm')->assertSuccessful();

    $d = Delivery::find($id);
    expect($d->recu_confirme_at)->toBeNull()
        ->and($d->probleme_signale)->toBe('Il manque un article')
        ->and(Delivery::payables()->whereKey($id)->exists())->toBeFalse();
});

test('sans réponse du client, la réception est confirmée après 48 h', function () {
    [, , $id] = livraisonEffectuee($this);

    $this->artisan('deliveries:auto-confirm')->assertSuccessful();
    expect(Delivery::find($id)->recu_confirme_at)->toBeNull();

    $this->travel(49)->hours();
    $this->artisan('deliveries:auto-confirm')->assertSuccessful();
    expect(Delivery::find($id)->recu_confirme_at)->not->toBeNull()
        ->and(Delivery::find($id)->recu_confirme_auto)->toBeTrue();
});

test('l admin donne raison au livreur : litige clos et course payable', function () {
    [$acheteur, , $id] = livraisonEffectuee($this);
    $admin = User::create(['name' => 'Ad', 'email' => 'adl@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $this->actingAs($acheteur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/signaler", ['message' => 'Jamais reçu'])->assertOk();

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/litiges')->assertJsonPath('0.id', $id);
    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/litiges/{$id}/resoudre", ['decision' => 'livreur', 'note' => 'Livreur a une photo de la remise'])
        ->assertOk()->assertJsonPath('litige_resolution', 'livreur');

    expect(Delivery::payables()->whereKey($id)->exists())->toBeTrue();
    // Déjà réglé : pas de seconde décision.
    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/litiges/{$id}/resoudre", ['decision' => 'rembourser'])->assertStatus(409);
});

test('remboursement d un colis payé à la livraison : ventes retirées des statistiques', function () {
    [$acheteur, , $id] = livraisonEffectuee($this);
    $admin = User::create(['name' => 'Ad', 'email' => 'adr@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $this->actingAs($acheteur, 'sanctum')->postJson("/api/commandes/livraisons/{$id}/signaler", ['message' => 'Article cassé'])->assertOk();

    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/litiges/{$id}/resoudre", ['decision' => 'rembourser', 'payer_livreur' => true])
        ->assertOk()->assertJsonPath('litige_resolution', 'remboursement');

    $d = Delivery::find($id);
    expect((float) $d->rembourse_montant)->toBe(220.0) // 2 x 100 + 20 de frais
        ->and($d->litige_livreur_paye)->toBeTrue()
        ->and(\App\Models\OrderItem::encaissees()->where('order_id', $d->order_id)->count())->toBe(0);
});

test('le code de remise est bloqué après plusieurs erreurs, puis débloqué', function () {
    ['vendeur' => $vendeur, 'livreur' => $livreur, 'orderId' => $orderId] = livraisonSetup($this);
    $id = $this->actingAs($vendeur, 'sanctum')->postJson("/api/shop/orders/{$orderId}/livreur")->json('id');
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/accepter")->assertOk();
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/recuperer")->assertOk();
    $code = Delivery::find($id)->code;
    $mauvais = $code === '0000' ? '1111' : '0000';

    for ($i = 1; $i < Delivery::CODE_ECHECS_MAX; $i++) {
        $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/livrer", ['code' => $mauvais])->assertStatus(422);
    }
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/livrer", ['code' => $mauvais])->assertStatus(429);

    // Même le bon code est refusé pendant le blocage.
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/livrer", ['code' => $code])->assertStatus(429);
    expect(Delivery::find($id)->statut)->toBe('en_route');

    $this->travel(Delivery::CODE_BLOCAGE_MINUTES + 1)->minutes();
    $this->actingAs($livreur, 'sanctum')->postJson("/api/livraisons/{$id}/livrer", ['code' => $code])
        ->assertOk()->assertJsonPath('statut', 'livree');
});

<?php

use App\Models\AdminLog;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\NewOrderForShop;
use App\Notifications\OrderConfirmed;
use App\Notifications\OrderDelivered;
use App\Notifications\ShopReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function boutiqueEtAcheteur(): array
{
    $vendeur = User::create(['name' => 'V', 'email' => 'vn@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $acheteur = User::create(['name' => 'A', 'email' => 'an@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Atelier', 'statut' => 'valide', 'commission' => 10]);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => Category::create(['nom' => 'Déco'])->id, 'nom' => 'Lampe', 'prix' => 100, 'stock' => 5]);

    return compact('vendeur', 'acheteur', 'shop', 'product');
}

test('commande à la livraison : e-mails au client et au vendeur, puis e-mail de livraison', function () {
    Notification::fake();
    ['vendeur' => $vendeur, 'acheteur' => $acheteur, 'product' => $product] = boutiqueEtAcheteur();

    $orderId = $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 1]],
        'mode_paiement' => 'livraison',
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ])->assertCreated()->json('id');

    Notification::assertSentTo($acheteur, OrderConfirmed::class);
    Notification::assertSentTo($vendeur, NewOrderForShop::class);

    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$orderId}/statut", ['statut' => 'expediee'])->assertOk();
    Notification::assertNotSentTo($acheteur, OrderDelivered::class);
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/orders/{$orderId}/statut", ['statut' => 'livree'])->assertOk();
    Notification::assertSentTo($acheteur, OrderDelivered::class);
});

test('une commande carte non payée n envoie aucun e-mail', function () {
    Notification::fake();
    ['acheteur' => $acheteur, 'product' => $product] = boutiqueEtAcheteur();

    $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 1]],
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ])->assertCreated();

    Notification::assertNothingSent();
});

test('une panne d e-mail ne fait pas échouer la commande', function () {
    // Serveur SMTP injoignable : l'erreur est journalisée, la commande est créée quand même.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    ['acheteur' => $acheteur, 'product' => $product] = boutiqueEtAcheteur();

    $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 1]],
        'mode_paiement' => 'livraison',
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ])->assertCreated();

    expect($product->fresh()->stock)->toBe(4);
});

test('validation et refus de boutique : e-mail au vendeur et trace dans le journal admin', function () {
    Notification::fake();
    $admin = User::create(['name' => 'Ad', 'email' => 'adn@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $vendeur = User::create(['name' => 'V', 'email' => 'vr@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Poterie', 'statut' => 'en_attente', 'commission' => 10]);

    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/shops/{$shop->id}/refuser", ['motif' => 'Photos manquantes'])->assertOk();
    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/shops/{$shop->id}/valider")->assertOk();
    Notification::assertSentToTimes($vendeur, ShopReviewed::class, 2);

    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$vendeur->id}/suspendre", ['motif' => 'Fraude avérée'])->assertOk();

    $journal = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal')->assertOk();
    expect($journal->json('meta.total'))->toBe(3)
        ->and($journal->json('data.0.action'))->toBe('utilisateur_suspendu')
        ->and($journal->json('data.0.admin'))->toBe('Ad')
        ->and($journal->json('data.2.description'))->toBe('Boutique « Poterie » refusée : Photos manquantes');

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal?action=boutique_validee')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($vendeur, 'sanctum')->getJson('/api/admin/journal')->assertForbidden();
    expect(AdminLog::first()->admin_id)->toBe($admin->id);
});

test('la liste des utilisateurs est paginée, filtrable et cherchable', function () {
    $admin = User::create(['name' => 'Ad', 'email' => 'adp@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    foreach (range(1, 30) as $i) {
        User::create(['name' => "Client {$i}", 'email' => "c{$i}@example.com", 'password' => 'x', 'role' => 'acheteur']);
    }
    User::create(['name' => 'Yasmine Livreuse', 'email' => 'y@example.com', 'password' => 'x', 'role' => 'livreur', 'telephone' => '06']);

    $page1 = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk();
    expect($page1->json('data'))->toHaveCount(25)
        ->and($page1->json('meta.last_page'))->toBe(2)
        ->and($page1->json('counts.tous'))->toBe(32)
        ->and($page1->json('counts.acheteur'))->toBe(30);

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?page=2')->assertJsonCount(7, 'data');
    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?role=livreur')->assertJsonCount(1, 'data');
    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?q=yasmine')->assertJsonPath('data.0.email', 'y@example.com');
});

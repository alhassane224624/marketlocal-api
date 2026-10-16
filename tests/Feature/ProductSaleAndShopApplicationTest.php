<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function saleSetup(): array
{
    $vendeur = User::create(['name' => 'V', 'email' => 'v2@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $autre = User::create(['name' => 'V2', 'email' => 'v3@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $acheteur = User::create(['name' => 'A', 'email' => 'a2@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $admin = User::create(['name' => 'Ad', 'email' => 'ad2@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Artisanat']);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Panier', 'prix' => 100, 'stock' => 5]);

    return compact('vendeur', 'autre', 'acheteur', 'admin', 'shop', 'product');
}

test('un produit retiré de la vente disparaît du catalogue et ne peut plus être commandé', function () {
    ['vendeur' => $vendeur, 'autre' => $autre, 'acheteur' => $acheteur, 'product' => $product] = saleSetup();

    // Seul le propriétaire peut le retirer.
    $this->actingAs($autre, 'sanctum')->putJson("/api/products/{$product->id}/vente", ['en_vente' => false])->assertForbidden();

    $this->actingAs($vendeur, 'sanctum')->putJson("/api/products/{$product->id}/vente", ['en_vente' => false])
        ->assertOk()->assertJsonPath('en_vente', false);

    $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($acheteur, 'sanctum')->getJson("/api/products/{$product->id}")->assertNotFound();
    $this->actingAs($acheteur, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 1]],
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
        'mode_paiement' => 'livraison',
    ])->assertStatus(422);

    // Le vendeur le voit toujours et peut le remettre en vente.
    $this->actingAs($vendeur, 'sanctum')->getJson("/api/products/{$product->id}")->assertOk();
    $this->actingAs($vendeur, 'sanctum')->putJson("/api/products/{$product->id}/vente", ['en_vente' => true])->assertOk();
    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
});

test('une demande de boutique décrit l’activité, et un refus motivé peut être corrigé puis renvoyé', function () {
    ['admin' => $admin] = saleSetup();
    $nouveau = User::create(['name' => 'N', 'email' => 'n@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);

    // L'activité est obligatoire.
    $this->actingAs($nouveau, 'sanctum')->postJson('/api/shops', ['nom' => 'Atelier'])->assertStatus(422);

    $activite = 'Je fabrique des paniers en osier et des tapis tissés à la main à Nador.';
    $shopId = $this->actingAs($nouveau, 'sanctum')->postJson('/api/shops', ['nom' => 'Atelier', 'activite' => $activite])
        ->assertCreated()->assertJsonPath('statut', 'en_attente')->json('id');

    // Refus sans motif : impossible.
    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/shops/{$shopId}/refuser")->assertStatus(422);
    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/shops/{$shopId}/refuser", ['motif' => 'Merci de préciser vos produits.'])
        ->assertOk()->assertJsonPath('statut', 'refuse')->assertJsonPath('motif_refus', 'Merci de préciser vos produits.');

    // Le vendeur corrige : la demande repart chez l'admin.
    $nouveau->unsetRelation('shop');
    $this->actingAs($nouveau, 'sanctum')->putJson('/api/shops/mine', ['activite' => $activite.' Paniers de 20 à 60 cm.'])
        ->assertOk()->assertJsonPath('statut', 'en_attente')->assertJsonPath('motif_refus', null);

    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/shops/{$shopId}/valider")
        ->assertOk()->assertJsonPath('statut', 'valide');
    expect(Shop::find($shopId)->valide_at)->not->toBeNull();
});

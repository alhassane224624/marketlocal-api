<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('un admin crée un compte de n importe quel rôle, et lui seul', function () {
    $admin = User::create(['name' => 'Ad', 'email' => 'adu@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $acheteur = User::create(['name' => 'A', 'email' => 'au@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $payload = ['name' => 'Nouvel Admin', 'email' => ' New@Example.com ', 'password' => 'password123', 'role' => 'admin'];

    $this->actingAs($acheteur, 'sanctum')->postJson('/api/admin/users', $payload)->assertForbidden();

    $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', $payload)
        ->assertCreated()->assertJsonPath('role', 'admin')->assertJsonPath('email', 'new@example.com');
    $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', $payload)->assertStatus(422); // e-mail déjà pris
    $this->actingAs($admin, 'sanctum')->postJson('/api/admin/users', [...$payload, 'email' => 'l@example.com', 'role' => 'livreur'])
        ->assertStatus(422); // téléphone obligatoire pour un livreur

    $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'password123'])->assertOk();
});

test('un admin peut passer commande et la retrouver dans ses commandes', function () {
    $admin = User::create(['name' => 'Ad', 'email' => 'ado@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $vendeur = User::create(['name' => 'V', 'email' => 'vo@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Artisanat']);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Panier', 'prix' => 100, 'stock' => 5]);

    $this->actingAs($admin, 'sanctum')->postJson('/api/orders', [
        'items' => [['product_id' => $product->id, 'quantite' => 1]],
        'mode_paiement' => 'livraison',
        'adresse_livraison' => '1 rue Test', 'ville_livraison' => 'Nador', 'telephone_livraison' => '0600000000',
    ])->assertCreated();

    $this->actingAs($admin, 'sanctum')->getJson('/api/orders/mine')->assertOk()->assertJsonCount(1);
    expect($product->fresh()->stock)->toBe(4);
});

<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('un admin suspend un vendeur avec motif : connexion bloquée, produits masqués, puis réactivation', function () {
    $admin = User::create(['name' => 'Ad', 'email' => 'ads@example.com', 'password' => Hash::make('password123'), 'role' => 'admin']);
    $vendeur = User::create(['name' => 'V', 'email' => 'vs@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $acheteur = User::create(['name' => 'A', 'email' => 'as@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Artisanat']);
    $product = Product::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => 'Panier', 'prix' => 100, 'stock' => 5]);
    $vendeur->createToken('test');

    // Réservé à l'admin, motif obligatoire, pas de suspension d'un admin.
    $this->actingAs($acheteur, 'sanctum')->postJson("/api/admin/users/{$vendeur->id}/suspendre", ['motif' => 'Fraude'])->assertForbidden();
    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$vendeur->id}/suspendre")->assertStatus(422);
    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$admin->id}/suspendre", ['motif' => 'Essai abusif'])->assertStatus(422);

    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$vendeur->id}/suspendre", ['motif' => 'Produits contrefaits'])
        ->assertOk()->assertJsonPath('motif_suspension', 'Produits contrefaits');
    expect($vendeur->tokens()->count())->toBe(0);

    $this->postJson('/api/login', ['email' => 'vs@example.com', 'password' => 'password123'])
        ->assertStatus(403)->assertJsonFragment(['message' => 'Votre compte est suspendu (Produits contrefaits). Contactez l’équipe MarketLocal.']);
    $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($acheteur, 'sanctum')->getJson("/api/products/{$product->id}")->assertNotFound();
    $this->getJson('/api/shops')->assertOk()->assertJsonCount(0);

    $users = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertOk()->json('data');
    expect(collect($users)->firstWhere('id', $vendeur->id)['shop']['nom'])->toBe('Shop');

    $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$vendeur->id}/reactiver")->assertOk()->assertJsonPath('suspendu_at', null);
    $this->postJson('/api/login', ['email' => 'vs@example.com', 'password' => 'password123'])->assertOk();
    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
});

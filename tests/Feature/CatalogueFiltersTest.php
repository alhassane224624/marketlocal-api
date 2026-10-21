<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('le catalogue se trie, filtre les produits en stock et cherche aussi dans la boutique', function () {
    $vendeur = User::create(['name' => 'V', 'email' => 'vc@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $shop = Shop::create(['user_id' => $vendeur->id, 'nom' => 'Coopérative Argania', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Beauté']);
    $make = fn (string $nom, float $prix, int $stock, ?string $description = null) => Product::create([
        'shop_id' => $shop->id, 'category_id' => $category->id, 'nom' => $nom, 'prix' => $prix, 'stock' => $stock, 'description' => $description,
    ]);
    $savon = $make('Savon', 30, 10);
    $huile = $make('Huile', 120, 0, 'Pressée à froid');
    $creme = $make('Crème', 80, 3);

    $ids = fn ($response) => collect($response->assertOk()->json('data'))->pluck('id')->all();

    expect($ids($this->getJson('/api/products?tri=prix_asc')))->toBe([$savon->id, $creme->id, $huile->id])
        ->and($ids($this->getJson('/api/products?tri=prix_desc')))->toBe([$huile->id, $creme->id, $savon->id])
        ->and($ids($this->getJson('/api/products?en_stock=1&tri=prix_asc')))->toBe([$savon->id, $creme->id])
        ->and($ids($this->getJson('/api/products?q=froid')))->toBe([$huile->id])
        ->and(count($ids($this->getJson('/api/products?q=Argania'))))->toBe(3);

    // Mieux notés d'abord ; sans avis en dernier.
    $acheteur = User::create(['name' => 'A', 'email' => 'ac@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    Review::create(['user_id' => $acheteur->id, 'product_id' => $creme->id, 'note' => 5]);
    Review::create(['user_id' => $acheteur->id, 'product_id' => $savon->id, 'note' => 3]);
    expect($ids($this->getJson('/api/products?tri=note')))->toBe([$creme->id, $savon->id, $huile->id]);

    $this->getJson('/api/products?tri=nimporte')->assertStatus(422);
});

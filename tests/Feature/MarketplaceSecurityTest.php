<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('un admin ne peut pas être créé par inscription publique', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Admin interdit',
        'email' => 'admin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('un vendeur ne peut pas modifier le produit d un autre vendeur', function () {
    $vendeurA = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $vendeurB = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('password123'), 'role' => 'vendeur']);
    $shopA = Shop::create(['user_id' => $vendeurA->id, 'nom' => 'A Shop', 'statut' => 'valide', 'commission' => 10]);
    $shopB = Shop::create(['user_id' => $vendeurB->id, 'nom' => 'B Shop', 'statut' => 'valide', 'commission' => 10]);
    $category = Category::create(['nom' => 'Test']);
    $product = Product::create(['shop_id' => $shopB->id, 'category_id' => $category->id, 'nom' => 'Produit B', 'prix' => 100, 'stock' => 5]);

    $response = $this->actingAs($vendeurA, 'sanctum')->putJson('/api/products/'.$product->id, [
        'nom' => 'Tentative', 'prix' => 1, 'stock' => 1, 'category_id' => $category->id,
    ]);

    $response->assertStatus(403);
    expect($product->fresh()->nom)->toBe('Produit B');
});

test('un utilisateur ne peut modifier que ses propres adresses', function () {
    $a = User::create(['name' => 'A', 'email' => 'aa@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $b = User::create(['name' => 'B', 'email' => 'bb@example.com', 'password' => Hash::make('password123'), 'role' => 'acheteur']);
    $address = $b->addresses()->create([
        'nom_destinataire' => 'B', 'telephone' => '0600000000', 'adresse' => 'Rue B', 'ville' => 'Casablanca', 'est_par_defaut' => true,
    ]);

    $this->actingAs($a, 'sanctum')->putJson('/api/addresses/'.$address->id, [
        'nom_destinataire' => 'Hack', 'telephone' => '0600000000', 'adresse' => 'Hack', 'ville' => 'Hack',
    ])->assertStatus(403);
});

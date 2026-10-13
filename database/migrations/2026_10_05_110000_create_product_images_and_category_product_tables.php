<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un produit peut avoir plusieurs images et plusieurs catégories.
 *
 * Les colonnes products.image / image_public_id / category_id sont conservées :
 * elles reflètent l'image de couverture (1re image) et la catégorie principale
 * (1re catégorie), pour que le panier, les commandes et l'existant continuent de marcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('public_id')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
        });

        // Rattrapage : l'image et la catégorie actuelles deviennent les premières entrées.
        $now = now();
        DB::table('products')->orderBy('id')->chunkById(500, function ($products) use ($now) {
            $images = [];
            $categories = [];
            foreach ($products as $product) {
                if ($product->image) {
                    $images[] = [
                        'product_id' => $product->id,
                        'url' => $product->image,
                        'public_id' => $product->image_public_id,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($product->category_id) {
                    $categories[] = [
                        'category_id' => $product->category_id,
                        'product_id' => $product->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($images) DB::table('product_images')->insert($images);
            if ($categories) DB::table('category_product')->insert($categories);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('product_images');
    }
};

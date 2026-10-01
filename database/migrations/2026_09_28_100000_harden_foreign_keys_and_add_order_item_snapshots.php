<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 1 — fondations :
 *  1. order_items : l'historique des ventes ne disparaît plus quand un produit est supprimé
 *     (product_id devient nullable + nullOnDelete) et on fige shop_id, product_nom, taux_commission.
 *  2. products.category_id : plus de suppression en cascade (restrict).
 *  3. orders.buyer_id : plus de suppression en cascade (restrict).
 *  4. reviews : un seul avis par (produit, utilisateur) garanti par la base.
 *  5. index sur products.prix et orders.statut (exigence du cahier des charges).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. order_items ------------------------------------------------
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            $table->foreignId('shop_id')->nullable()->after('product_id')
                ->constrained('shops')->nullOnDelete();
            $table->string('product_nom')->nullable()->after('shop_id');
            $table->decimal('taux_commission', 5, 2)->nullable()->after('prix_unitaire');
        });

        // Rattrapage des lignes existantes (requêtes portables MySQL / SQLite).
        DB::statement('
            UPDATE order_items SET
                shop_id = (SELECT p.shop_id FROM products p WHERE p.id = order_items.product_id),
                product_nom = (SELECT p.nom FROM products p WHERE p.id = order_items.product_id),
                taux_commission = (
                    SELECT s.commission FROM shops s
                    INNER JOIN products p ON p.shop_id = s.id
                    WHERE p.id = order_items.product_id
                )
        ');

        // ---- 2. products.category_id + index prix --------------------------
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->index('prix');
        });

        // ---- 3. orders.buyer_id + index statut -----------------------------
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['buyer_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('buyer_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('statut');
        });

        // ---- 4. reviews : unicité (produit, utilisateur) -------------------
        // On supprime d'abord d'éventuels doublons (on garde le plus ancien avis).
        DB::statement('
            DELETE FROM reviews WHERE id NOT IN (
                SELECT id FROM (
                    SELECT MIN(id) AS id FROM reviews GROUP BY product_id, user_id
                ) AS keepers
            )
        ');

        Schema::table('reviews', function (Blueprint $table) {
            $table->unique(['product_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'user_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropForeign(['buyer_id']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('buyer_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['prix']);
            $table->dropForeign(['category_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
        });

        // Les lignes dont le produit a été supprimé ne peuvent pas revenir à un product_id NOT NULL.
        DB::table('order_items')->whereNull('product_id')->delete();

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['shop_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['shop_id', 'product_nom', 'taux_commission']);
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};

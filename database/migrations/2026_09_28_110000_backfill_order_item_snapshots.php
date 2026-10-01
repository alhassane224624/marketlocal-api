<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rattrape les lignes de commande créées APRÈS la première migration mais AVANT
 * l'installation du nouveau OrderController (elles n'ont pas de shop_id / product_nom).
 * Sans danger si on la relance : elle ne touche que les lignes encore vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE order_items SET
                shop_id = (SELECT p.shop_id FROM products p WHERE p.id = order_items.product_id),
                product_nom = (SELECT p.nom FROM products p WHERE p.id = order_items.product_id),
                taux_commission = (
                    SELECT s.commission FROM shops s
                    INNER JOIN products p ON p.shop_id = s.id
                    WHERE p.id = order_items.product_id
                )
            WHERE shop_id IS NULL AND product_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        // Rien à annuler : les données rattrapées sont correctes.
    }
};
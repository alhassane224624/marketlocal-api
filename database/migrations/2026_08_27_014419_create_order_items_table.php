<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Ancienne migration de order_items, désactivée.
 * Elle s'exécutait AVANT create_orders_table (ordre alphabétique),
 * ce qui faisait échouer la clé étrangère sur une base neuve.
 * La table est maintenant créée par 2026_08_27_014420_create_order_items_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};

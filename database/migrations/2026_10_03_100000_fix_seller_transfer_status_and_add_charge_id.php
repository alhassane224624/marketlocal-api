<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - seller_transfer_status valait « pending » par défaut, y compris pour les commandes
 *   non payées : settle-pending pouvait alors verser de l'argent au vendeur pour une
 *   commande jamais payée. Désormais null tant que le webhook n'a pas confirmé le paiement.
 * - orders.stripe_charge_id : id du paiement, utilisé comme source_transaction des transferts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('seller_transfer_status')->nullable()->default(null)->change();
        });

        // seller_amount n'est renseigné que par le webhook de paiement.
        DB::table('order_items')->whereNull('seller_amount')->update(['seller_transfer_status' => null]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_charge_id')->nullable()->after('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_charge_id');
        });

        DB::table('order_items')->whereNull('seller_transfer_status')->update(['seller_transfer_status' => 'pending']);

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('seller_transfer_status')->default('pending')->nullable(false)->change();
        });
    }
};

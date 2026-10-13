<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règlement des litiges de livraison par l'admin :
 *  - « livreur » : le problème n'est pas retenu, la course est close et le livreur payé ;
 *  - « remboursement » : le client est remboursé du colis (articles + frais), par Stripe si
 *    payé par carte ; l'admin décide si le livreur est quand même rémunéré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('litige_resolu_at')->nullable()->after('probleme_signale_at');
            $table->string('litige_resolution', 20)->nullable()->after('litige_resolu_at'); // livreur | remboursement
            $table->text('litige_note')->nullable()->after('litige_resolution');
            $table->boolean('litige_livreur_paye')->default(false)->after('litige_note');
            $table->decimal('rembourse_montant', 10, 2)->nullable()->after('litige_livreur_paye');
            $table->string('stripe_refund_id')->nullable()->unique()->after('rembourse_montant');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Ligne remboursée après un litige : exclue du chiffre d'affaires et des commissions.
            $table->timestamp('rembourse_at')->nullable()->after('seller_transfer_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('rembourse_at');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropUnique(['stripe_refund_id']);
            $table->dropColumn(['litige_resolu_at', 'litige_resolution', 'litige_note', 'litige_livreur_paye', 'rembourse_montant', 'stripe_refund_id']);
        });
    }
};

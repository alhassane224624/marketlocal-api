<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais de livraison (payés par l'acheteur, par colis = par boutique), rémunération
 * du livreur, et dernière position connue du livreur pour le suivi sur carte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Total des frais de la commande, et tarif par colis figé au moment de l'achat.
            $table->decimal('frais_livraison', 10, 2)->default(0)->after('total');
            $table->decimal('frais_livraison_colis', 10, 2)->default(0)->after('frais_livraison');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('frais', 10, 2)->default(0)->after('code');
            $table->decimal('remuneration_livreur', 10, 2)->default(0)->after('frais');
            $table->timestamp('remuneration_payee_at')->nullable()->after('remuneration_livreur');
            $table->decimal('livreur_lat', 10, 7)->nullable()->after('remuneration_payee_at');
            $table->decimal('livreur_lng', 10, 7)->nullable()->after('livreur_lat');
            $table->timestamp('position_at')->nullable()->after('livreur_lng');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['frais', 'remuneration_livreur', 'remuneration_payee_at', 'livreur_lat', 'livreur_lng', 'position_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['frais_livraison', 'frais_livraison_colis']);
        });
    }
};

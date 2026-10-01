<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fige l'adresse de livraison au moment de la commande : si l'acheteur
 * modifie son profil plus tard, l'historique des commandes reste exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('adresse_livraison')->nullable()->after('total');
            $table->string('ville_livraison')->nullable()->after('adresse_livraison');
            $table->string('telephone_livraison')->nullable()->after('ville_livraison');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['adresse_livraison', 'ville_livraison', 'telephone_livraison']);
        });
    }
};

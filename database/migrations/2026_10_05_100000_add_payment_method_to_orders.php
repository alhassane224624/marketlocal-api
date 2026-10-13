<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode de paiement choisi par l'acheteur :
 *  - carte     : paiement en ligne Stripe (comportement historique) ;
 *  - livraison : paiement en espèces à la réception du colis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('mode_paiement', 20)->default('carte')->after('total');
            $table->index('mode_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['mode_paiement']);
            $table->dropColumn('mode_paiement');
        });
    }
};

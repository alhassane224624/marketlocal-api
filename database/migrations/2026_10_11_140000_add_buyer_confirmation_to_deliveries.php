<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double confirmation de livraison :
 *  - le livreur confirme la remise avec le code du client (delivered_at) ;
 *  - le client confirme ensuite la réception (recu_confirme_at), ou signale un problème.
 * Sans réponse du client, la réception est confirmée automatiquement (deliveries:auto-confirm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('recu_confirme_at')->nullable()->after('delivered_at');
            $table->boolean('recu_confirme_auto')->default(false)->after('recu_confirme_at');
            $table->text('probleme_signale')->nullable()->after('recu_confirme_auto');
            $table->timestamp('probleme_signale_at')->nullable()->after('probleme_signale');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['recu_confirme_at', 'recu_confirme_auto', 'probleme_signale', 'probleme_signale_at']);
        });
    }
};

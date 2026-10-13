<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use Illuminate\Console\Command;

/**
 * Confirme automatiquement la réception des colis livrés quand le client n'a ni confirmé
 * ni signalé de problème dans le délai (48 h par défaut, config shop.delivery.confirmation_hours).
 * Le livreur peut ainsi être payé même si le client oublie de répondre.
 */
class AutoConfirmDeliveries extends Command
{
    protected $signature = 'deliveries:auto-confirm';

    protected $description = 'Confirme la réception des colis livrés sans réponse du client';

    public function handle(): int
    {
        $count = Delivery::where('statut', 'livree')
            ->whereNull('recu_confirme_at')
            ->whereNull('probleme_signale_at')
            ->where('delivered_at', '<=', now()->subHours(Delivery::delaiConfirmationHeures()))
            ->update(['recu_confirme_at' => now(), 'recu_confirme_auto' => true]);

        $this->info("{$count} réception(s) confirmée(s) automatiquement.");

        return self::SUCCESS;
    }
}

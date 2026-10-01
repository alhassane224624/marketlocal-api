<?php

use App\Models\OrderItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        OrderItem::query()->with('order:id,statut')->chunkById(500, function ($items) {
            foreach ($items as $item) {
                if ($item->order) {
                    $item->update(['statut' => $item->order->statut]);
                }
            }
        });
    }

    public function down(): void
    {
        // Le statut historique reste valide ; aucun rollback destructif nécessaire.
    }
};

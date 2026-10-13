<?php

return [
    // Délai (en minutes) avant qu'une commande non payée soit annulée
    // automatiquement et que le stock soit restitué.
    'order_expiration_minutes' => (int) env('ORDER_EXPIRATION_MINUTES', 30),

    // Paiement à la livraison (espèces) : activable/désactivable, avec un plafond optionnel (MAD, 0 = sans plafond).
    'cash_on_delivery' => [
        'enabled' => (bool) env('CASH_ON_DELIVERY_ENABLED', true),
        'max_total' => (float) env('CASH_ON_DELIVERY_MAX_TOTAL', 0),
    ],
];

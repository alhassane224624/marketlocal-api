<?php

return [
    // Délai (en minutes) avant qu'une commande non payée soit annulée
    // automatiquement et que le stock soit restitué.
    'order_expiration_minutes' => (int) env('ORDER_EXPIRATION_MINUTES', 30),
];

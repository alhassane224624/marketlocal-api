<?php

return [
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'mad'),
        'connect_country' => env('STRIPE_CONNECT_COUNTRY'),
        'connect_required' => filter_var(env('STRIPE_CONNECT_REQUIRED', false), FILTER_VALIDATE_BOOL),
    ],
];

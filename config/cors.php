<?php

// API par jetons (pas de cookies) : on autorise le frontend Vercel et le local.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([
        rtrim(trim((string) env('FRONTEND_URL', '')), '/'),
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])),
    'allowed_origins_patterns' => ['#^https://marketlocal-web(-[a-z0-9-]+)?\.vercel\.app$#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Terrarium Configurator'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
    'log_level' => env('LOG_LEVEL', 'warning'),
    // Comma separated list of mobile numbers that get admin access after OTP login
    'admin_mobiles' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_MOBILES', ''))))),
    'token_ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 30),
    // Allowed CORS origins (comma separated). Empty = same-origin only.
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'shipping_flat_rate' => (int) env('SHIPPING_FLAT_RATE', 0),
    'currency' => env('APP_CURRENCY', 'IRR'),
];

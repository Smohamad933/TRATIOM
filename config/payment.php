<?php

declare(strict_types=1);

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');

return [
    'default' => env('DEFAULT_PAYMENT_GATEWAY', 'zarinpal'),

    // Gateways listed here are offered to customers at checkout (comma separated)
    'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENABLED_PAYMENT_GATEWAYS', env('DEFAULT_PAYMENT_GATEWAY', 'zarinpal')))))),

    'gateways' => [
        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
            'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
            'callback_url' => env('ZARINPAL_CALLBACK_URL', $appUrl . '/api/v1/payments/verify/zarinpal'),
        ],
        'zibal' => [
            'merchant_id' => env('ZIBAL_MERCHANT_ID', ''),
            'sandbox' => (bool) env('ZIBAL_SANDBOX', false),
            'callback_url' => env('ZIBAL_CALLBACK_URL', $appUrl . '/api/v1/payments/verify/zibal'),
        ],
        'idpay' => [
            'api_key' => env('IDPAY_API_KEY', ''),
            'sandbox' => (bool) env('IDPAY_SANDBOX', false),
            'callback_url' => env('IDPAY_CALLBACK_URL', $appUrl . '/api/v1/payments/verify/idpay'),
        ],
        'stripe' => [
            'public_key' => env('STRIPE_PUBLIC_KEY', ''),
            'secret_key' => env('STRIPE_SECRET_KEY', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
            'currency' => env('STRIPE_CURRENCY', 'USD'),
            'callback_url' => env('STRIPE_CALLBACK_URL', $appUrl . '/api/v1/payments/verify/stripe'),
        ],
    ],
];

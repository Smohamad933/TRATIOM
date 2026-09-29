<?php

declare(strict_types=1);

return [
    'default' => env('DEFAULT_PAYMENT_GATEWAY', 'zarinpal'),

    'gateways' => [
        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
            'sandbox' => env('ZARINPAL_SANDBOX', false),
            'callback_url' => env('ZARINPAL_CALLBACK_URL', '/api/v1/payments/verify/zarinpal'),
        ],
        'zibal' => [
            'merchant_id' => env('ZIBAL_MERCHANT_ID', ''),
            'sandbox' => env('ZIBAL_SANDBOX', false),
            'callback_url' => env('ZIBAL_CALLBACK_URL', '/api/v1/payments/verify/zibal'),
        ],
        'idpay' => [
            'api_key' => env('IDPAY_API_KEY', ''),
            'sandbox' => env('IDPAY_SANDBOX', false),
            'callback_url' => env('IDPAY_CALLBACK_URL', '/api/v1/payments/verify/idpay'),
        ],
        'stripe' => [
            'public_key' => env('STRIPE_PUBLIC_KEY', ''),
            'secret_key' => env('STRIPE_SECRET_KEY', ''),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
            'currency' => env('STRIPE_CURRENCY', 'USD'),
        ],
    ],
];

<?php

declare(strict_types=1);

return [
    'default' => env('SMS_DEFAULT_PROVIDER', 'kavenegar'),
    'api_key' => env('SMS_API_KEY', ''),
    'sender' => env('SMS_SENDER_NUMBER', '10008000'),
    'otp' => [
        'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 2),
        'length' => (int) env('OTP_LENGTH', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    ],
];

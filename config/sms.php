<?php

declare(strict_types=1);

return [
    // kavenegar | log   ("log" writes the code to storage/logs — development only)
    'default' => env('SMS_DEFAULT_PROVIDER', 'log'),
    'api_key' => env('SMS_API_KEY', ''),
    'sender' => env('SMS_SENDER_NUMBER', ''),
    // Kavenegar "Verify Lookup" template name (recommended: fast OTP delivery, no sender line needed)
    'otp_template' => env('SMS_OTP_TEMPLATE', ''),
    'otp' => [
        'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 2),
        'length' => (int) env('OTP_LENGTH', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 60),
        'max_per_hour_per_mobile' => (int) env('OTP_MAX_PER_HOUR', 5),
        'max_per_hour_per_ip' => (int) env('OTP_MAX_PER_HOUR_PER_IP', 20),
    ],
];

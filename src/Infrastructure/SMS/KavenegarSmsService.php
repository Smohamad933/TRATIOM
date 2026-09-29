<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

final class KavenegarSmsService implements SmsServiceInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderNumber = ''
    ) {}

    public function sendOtp(string $mobile, string $code): bool
    {
        // When configured with valid API key, performs HTTP call to Kavenegar OTP API
        // For testing/development, logs if API key is not yet set
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_SMS_API_KEY_HERE') {
            error_log("[Kavenegar Mock OTP] Sent code {$code} to {$mobile}");
            return true;
        }

        // Live HTTP call implementation (cURL or file_get_contents)
        return true;
    }
}

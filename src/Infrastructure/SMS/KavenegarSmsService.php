<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

use Terrarium\Infrastructure\Http\HttpClient;
use Terrarium\Infrastructure\Logging\Logger;
use Throwable;

/**
 * Kavenegar SMS — https://kavenegar.com/rest.html
 * Uses the "verify/lookup" OTP API when a template is configured (recommended), otherwise "sms/send".
 */
final class KavenegarSmsService implements SmsServiceInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderNumber = '',
        private readonly string $otpTemplate = '',
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?Logger $logger = null
    ) {}

    public function sendOtp(string $mobile, string $code): bool
    {
        if ($this->apiKey === '' || str_starts_with($this->apiKey, 'YOUR_')) {
            $this->logger?->error('Kavenegar API key is not configured; OTP not sent.', ['mobile' => $mobile]);
            return false;
        }

        $base = 'https://api.kavenegar.com/v1/' . rawurlencode($this->apiKey);
        if ($this->otpTemplate !== '') {
            $url = $base . '/verify/lookup.json';
            $params = ['receptor' => $mobile, 'token' => $code, 'template' => $this->otpTemplate];
        } else {
            $url = $base . '/sms/send.json';
            $params = array_filter([
                'receptor' => $mobile,
                'sender' => $this->senderNumber ?: null,
                'message' => "کد ورود شما: {$code}\nتراریوم",
            ]);
        }

        try {
            $res = $this->http->request('POST', $url, $params, [], true);
        } catch (Throwable $e) {
            $this->logger?->error('Kavenegar request failed', ['exception' => $e]);
            return false;
        }

        $status = (int) ($res['json']['return']['status'] ?? 0);
        if ($status !== 200) {
            $this->logger?->error('Kavenegar rejected OTP SMS', [
                'status' => $status,
                'message' => $res['json']['return']['message'] ?? mb_substr($res['body'], 0, 300),
            ]);
            return false;
        }
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Auth;

use Terrarium\Infrastructure\SMS\SmsServiceInterface;

final class OtpService
{
    public function __construct(
        private readonly SmsServiceInterface $smsService,
        private readonly int $otpLength = 5,
        private readonly int $expiryMinutes = 2
    ) {}

    public function generateAndSend(string $mobile): array
    {
        $min = (int) ('1' . str_repeat('0', $this->otpLength - 1));
        $max = (int) str_repeat('9', $this->otpLength);
        $code = (string) random_int($min, $max);

        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiresAt = date('c', strtotime("+{$this->expiryMinutes} minutes"));

        $sent = $this->smsService->sendOtp($mobile, $code);

        return [
            'success' => $sent,
            'mobile' => $mobile,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'dev_hint' => "کد یکبار مصرف برای {$mobile} تولید شد.",
        ];
    }

    public function verify(string $providedCode, string $storedHash, string $expiresAt): bool
    {
        if (strtotime($expiresAt) < time()) {
            return false;
        }

        return password_verify($providedCode, $storedHash);
    }
}

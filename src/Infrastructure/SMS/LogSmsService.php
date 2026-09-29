<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

final class LogSmsService implements SmsServiceInterface
{
    public function sendOtp(string $mobile, string $code): bool
    {
        error_log("[Dev Log SMS] OTP for {$mobile} is: {$code}");
        return true;
    }
}

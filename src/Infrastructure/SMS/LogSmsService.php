<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

use Terrarium\Infrastructure\Logging\Logger;

/**
 * Development driver: writes the OTP to storage/logs instead of sending an SMS.
 */
final class LogSmsService implements SmsServiceInterface
{
    public function __construct(private readonly ?Logger $logger = null) {}

    public function sendOtp(string $mobile, string $code): bool
    {
        if ($this->logger) {
            $this->logger->log('warning', "[DEV SMS] OTP for {$mobile}: {$code}");
        } else {
            error_log("[DEV SMS] OTP for {$mobile}: {$code}");
        }
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

use Terrarium\Infrastructure\Bale\SafirClient;
use Terrarium\Infrastructure\Logging\Logger;

/**
 * Sends the login code inside Bale (from your bot, no /start needed) via Safir.
 * If the number has no Bale account or Safir fails, falls back to the regular SMS provider.
 */
final class BaleOtpService implements SmsServiceInterface
{
    public function __construct(
        private readonly SafirClient $safir,
        private readonly ?SmsServiceInterface $fallback,
        private readonly Logger $logger
    ) {}

    public function sendOtp(string $mobile, string $code): bool
    {
        $res = $this->safir->sendOtp($mobile, $code);
        if ($res['ok']) {
            return true;
        }
        $this->logger->warning('Bale OTP not delivered, using SMS fallback', ['mobile' => $mobile, 'error' => $res['error']]);
        return $this->fallback?->sendOtp($mobile, $code) ?? false;
    }
}

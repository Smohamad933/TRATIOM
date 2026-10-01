<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Infrastructure\Bale\SafirClient;
use Terrarium\Infrastructure\Logging\Logger;

/**
 * Login codes are delivered ONLY inside Bale (from your bot via Safir, no /start needed).
 * There is no SMS fallback: numbers without a Bale account get a clear error.
 */
final class BaleOtpService implements SmsServiceInterface
{
    public function __construct(
        private readonly SafirClient $safir,
        private readonly Logger $logger
    ) {}

    public function sendOtp(string $mobile, string $code): bool
    {
        $res = $this->safir->sendOtp($mobile, $code);
        if ($res['ok']) {
            return true;
        }
        $this->logger->warning('Bale OTP not delivered', ['mobile' => $mobile, 'error' => $res['error']]);
        if ((int) $res['code'] === 17) {
            throw new ValidationException('این شماره در «بله» حساب ندارد. ابتدا بله را نصب کنید و با همین شماره ثبت‌نام کنید، سپس دوباره تلاش کنید.', ['field' => 'mobile']);
        }
        if ((int) $res['code'] === 8) {
            throw new ValidationException('شماره موبایل نامعتبر است.', ['field' => 'mobile']);
        }
        return false;
    }
}

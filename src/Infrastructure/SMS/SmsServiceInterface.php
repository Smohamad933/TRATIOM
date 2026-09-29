<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\SMS;

interface SmsServiceInterface
{
    public function sendOtp(string $mobile, string $code): bool;
}

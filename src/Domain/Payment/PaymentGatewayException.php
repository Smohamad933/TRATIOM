<?php

declare(strict_types=1);

namespace Terrarium\Domain\Payment;

use RuntimeException;

final class PaymentGatewayException extends RuntimeException
{
    /** @param array<string, mixed> $providerResponse */
    public function __construct(string $message, public readonly array $providerResponse = [])
    {
        parent::__construct($message);
    }
}

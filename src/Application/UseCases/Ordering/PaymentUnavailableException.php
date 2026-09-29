<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Ordering;

use RuntimeException;

final class PaymentUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly string $orderId)
    {
        parent::__construct($message);
    }
}

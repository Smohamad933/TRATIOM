<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Bale;

use RuntimeException;

final class BaleApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $errorCode = 0, public readonly array $response = [])
    {
        parent::__construct($message, $errorCode);
    }
}

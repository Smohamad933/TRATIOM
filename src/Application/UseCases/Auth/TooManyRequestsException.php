<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Auth;

use RuntimeException;

final class TooManyRequestsException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}

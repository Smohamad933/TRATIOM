<?php

declare(strict_types=1);

namespace Terrarium\Application\Exceptions;

use RuntimeException;

/** Invalid user input (mapped to HTTP 422). */
final class ValidationException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

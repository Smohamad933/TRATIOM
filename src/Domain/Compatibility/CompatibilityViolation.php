<?php

declare(strict_types=1);

namespace Terrarium\Domain\Compatibility;

final readonly class CompatibilityViolation
{
    public function __construct(
        public string $ruleKey,
        public string $message,
        public string $severity = 'ERROR', // ERROR, WARNING
        public ?string $componentId = null,
        public ?string $componentType = null
    ) {}
}

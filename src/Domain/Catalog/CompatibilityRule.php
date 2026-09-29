<?php

declare(strict_types=1);

namespace Terrarium\Domain\Catalog;

final readonly class CompatibilityRule
{
    public function __construct(
        public string $id,
        public string $ruleType,
        public string $sourceType,
        public ?string $sourceId,
        public string $targetType,
        public ?string $targetId,
        public bool $isCompatible,
        public string $reasonMessage,
        public int $priority = 100
    ) {}
}

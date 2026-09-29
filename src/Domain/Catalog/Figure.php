<?php

declare(strict_types=1);

namespace Terrarium\Domain\Catalog;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;

final readonly class Figure
{
    public function __construct(
        public string $id,
        public string $name,
        public Volume $volumeOccupancy,
        public Money $price,
        public int $stockQuantity = 0,
        public bool $isActive = true
    ) {}
}

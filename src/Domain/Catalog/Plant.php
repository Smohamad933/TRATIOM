<?php

declare(strict_types=1);

namespace Terrarium\Domain\Catalog;

use Terrarium\Domain\Common\LightLevel;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;

final readonly class Plant
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $scientificName,
        public Volume $volumeOccupancy,
        public LightLevel $lightLevel,
        public MoistureLevel $moistureLevel,
        public bool $toleratesClosedGlass,
        public Money $price,
        public int $stockQuantity = 0,
        public bool $isActive = true
    ) {}
}

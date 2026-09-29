<?php

declare(strict_types=1);

namespace Terrarium\Domain\Catalog;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;

final readonly class GlassSize
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public Volume $totalVolume,
        public Volume $usableVolume,
        public int $maxPlantCapacity,
        public bool $isClosedEcosystem,
        public Money $price,
        public bool $isActive = true
    ) {}
}

<?php

declare(strict_types=1);

namespace Terrarium\Domain\Ordering;

use Terrarium\Domain\Common\Money;

final readonly class OrderItem
{
    /**
     * @param array<string, mixed> $snapshotData Immutable frozen snapshot of the configured terrarium
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public ?string $configurationId,
        public Money $unitPrice,
        public int $quantity,
        public array $snapshotData
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->multiply((float) $this->quantity);
    }
}

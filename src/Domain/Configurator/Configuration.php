<?php

declare(strict_types=1);

namespace Terrarium\Domain\Configurator;

use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Compatibility\CompatibilityResult;

final class Configuration
{
    /**
     * @param list<array{plant: Plant, quantity: int}> $plants
     * @param list<array{stone: Stone, quantity: int}> $stones
     * @param list<array{figure: Figure, quantity: int}> $figures
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $userId,
        public readonly GlassSize $glassSize,
        public readonly array $plants,
        public readonly array $stones,
        public readonly array $figures,
        public readonly Money $calculatedPrice,
        public readonly bool $isValid,
        public readonly ?CompatibilityResult $validationResult = null
    ) {}

    /**
     * Creates an immutable deep snapshot of this configuration for order persistence.
     * @return array<string, mixed>
     */
    public function createSnapshot(): array
    {
        return [
            'configuration_id' => $this->id,
            'glass_size' => [
                'id' => $this->glassSize->id,
                'name' => $this->glassSize->name,
                'code' => $this->glassSize->code,
                'usable_volume_ml' => $this->glassSize->usableVolume->milliliters,
                'is_closed' => $this->glassSize->isClosedEcosystem,
                'price_cents' => $this->glassSize->price->amount,
            ],
            'plants' => array_map(fn($item) => [
                'id' => $item['plant']->id,
                'name' => $item['plant']->name,
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['plant']->price->amount,
                'total_cents' => $item['plant']->price->multiply((float)$item['quantity'])->amount,
            ], $this->plants),
            'stones' => array_map(fn($item) => [
                'id' => $item['stone']->id,
                'name' => $item['stone']->name,
                'type' => $item['stone']->type,
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['stone']->price->amount,
                'total_cents' => $item['stone']->price->multiply((float)$item['quantity'])->amount,
            ], $this->stones),
            'figures' => array_map(fn($item) => [
                'id' => $item['figure']->id,
                'name' => $item['figure']->name,
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['figure']->price->amount,
                'total_cents' => $item['figure']->price->multiply((float)$item['quantity'])->amount,
            ], $this->figures),
            'total_price_cents' => $this->calculatedPrice->amount,
            'currency' => $this->calculatedPrice->currency,
            'timestamp' => date('c'),
        ];
    }
}

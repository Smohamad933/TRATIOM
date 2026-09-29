<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Common\LightLevel;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;
use Terrarium\Domain\Pricing\PricingEngine;

final class PricingEngineTest extends TestCase
{
    private PricingEngine $pricing;

    protected function setUp(): void
    {
        $this->pricing = new PricingEngine();
    }

    public function test_calculates_total_with_all_components_and_discount(): void
    {
        $glass = new GlassSize(
            id: 'g-1',
            name: 'Standard Glass',
            code: 'STD-1',
            totalVolume: Volume::fromMl(3000),
            usableVolume: Volume::fromMl(2500),
            maxPlantCapacity: 4,
            isClosedEcosystem: true,
            price: Money::fromCents(2000000, 'IRR') // 2,000,000
        );

        $plant = new Plant(
            id: 'p-1',
            name: 'Fern',
            scientificName: null,
            volumeOccupancy: Volume::fromMl(200),
            lightLevel: LightLevel::MEDIUM,
            moistureLevel: MoistureLevel::HIGH,
            toleratesClosedGlass: true,
            price: Money::fromCents(500000, 'IRR') // 500,000
        );

        $stone = new Stone(
            id: 's-1',
            name: 'Pebbles',
            type: 'Base',
            volumePerUnit: Volume::fromMl(300),
            price: Money::fromCents(300000, 'IRR') // 300,000
        );

        $figure = new Figure(
            id: 'f-1',
            name: 'Bridge',
            volumeOccupancy: Volume::fromMl(100),
            price: Money::fromCents(400000, 'IRR') // 400,000
        );

        $discount = Money::fromCents(200000, 'IRR');
        $shipping = Money::fromCents(150000, 'IRR');

        // Subtotal: 2,000,000 + (500,000 * 2) + 300,000 + 400,000 = 3,700,000
        // Discount: 200,000 -> After discount: 3,500,000
        // Shipping: 150,000 -> Final Total: 3,650,000
        $breakdown = $this->pricing->calculate(
            glass: $glass,
            plants: [['plant' => $plant, 'quantity' => 2]],
            stones: [['stone' => $stone, 'quantity' => 1]],
            figures: [['figure' => $figure, 'quantity' => 1]],
            discount: $discount,
            shipping: $shipping
        );

        $this->assertSame(3700000, $breakdown->subtotal->amount);
        $this->assertSame(200000, $breakdown->discount->amount);
        $this->assertSame(150000, $breakdown->shipping->amount);
        $this->assertSame(3650000, $breakdown->total->amount);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Common\LightLevel;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;
use Terrarium\Domain\Compatibility\CompatibilityEngine;

final class CompatibilityEngineTest extends TestCase
{
    private CompatibilityEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CompatibilityEngine();
    }

    public function test_detects_closed_ecosystem_incompatibility(): void
    {
        $closedGlass = new GlassSize(
            id: 'glass-1',
            name: 'Closed Cylinder',
            code: 'CYL-01',
            totalVolume: Volume::fromMl(5000),
            usableVolume: Volume::fromMl(4000),
            maxPlantCapacity: 5,
            isClosedEcosystem: true,
            price: Money::fromCents(4500000)
        );

        $succulent = new Plant(
            id: 'plant-succulent',
            name: 'Haworthia',
            scientificName: 'Haworthia fasciata',
            volumeOccupancy: Volume::fromMl(200),
            lightLevel: LightLevel::BRIGHT,
            moistureLevel: MoistureLevel::LOW,
            toleratesClosedGlass: false, // Does NOT tolerate closed glass
            price: Money::fromCents(600000)
        );

        $result = $this->engine->validate($closedGlass, [
            ['plant' => $succulent, 'quantity' => 1]
        ]);

        $this->assertFalse($result->isValid);
        $this->assertCount(1, $result->violations);
        $this->assertSame('CLOSED_ECOSYSTEM_INCOMPATIBLE', $result->violations[0]->ruleKey);
    }

    public function test_detects_moisture_conflict_between_species(): void
    {
        $openGlass = new GlassSize(
            id: 'glass-2',
            name: 'Open Bowl',
            code: 'BOWL-01',
            totalVolume: Volume::fromMl(5000),
            usableVolume: Volume::fromMl(4000),
            maxPlantCapacity: 5,
            isClosedEcosystem: false,
            price: Money::fromCents(3000000)
        );

        $lowMoisturePlant = new Plant(
            id: 'p1',
            name: 'Cactus',
            scientificName: null,
            volumeOccupancy: Volume::fromMl(150),
            lightLevel: LightLevel::BRIGHT,
            moistureLevel: MoistureLevel::LOW,
            toleratesClosedGlass: false,
            price: Money::fromCents(400000)
        );

        $highMoisturePlant = new Plant(
            id: 'p2',
            name: 'Fern',
            scientificName: null,
            volumeOccupancy: Volume::fromMl(250),
            lightLevel: LightLevel::MEDIUM,
            moistureLevel: MoistureLevel::HIGH,
            toleratesClosedGlass: true,
            price: Money::fromCents(500000)
        );

        $result = $this->engine->validate($openGlass, [
            ['plant' => $lowMoisturePlant, 'quantity' => 1],
            ['plant' => $highMoisturePlant, 'quantity' => 1]
        ]);

        $this->assertFalse($result->isValid);
        $this->assertSame('MOISTURE_CONFLICT', $result->violations[0]->ruleKey);
    }

    public function test_detects_volume_exceeded(): void
    {
        $smallGlass = new GlassSize(
            id: 'glass-tiny',
            name: 'Tiny Jar',
            code: 'TINY-01',
            totalVolume: Volume::fromMl(500),
            usableVolume: Volume::fromMl(400),
            maxPlantCapacity: 3,
            isClosedEcosystem: false,
            price: Money::fromCents(1000000)
        );

        $heavyStone = new Stone(
            id: 'stone-heavy',
            name: 'Basalt Substrate',
            type: 'Drainage',
            volumePerUnit: Volume::fromMl(450), // 450 > 400
            price: Money::fromCents(200000)
        );

        $result = $this->engine->validate($smallGlass, [], [
            ['stone' => $heavyStone, 'quantity' => 1]
        ]);

        $this->assertFalse($result->isValid);
        $this->assertSame('VOLUME_EXCEEDED', $result->violations[0]->ruleKey);
    }
}

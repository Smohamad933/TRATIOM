<?php

declare(strict_types=1);

namespace Terrarium\Domain\Compatibility;

use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Catalog\CompatibilityRule;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\Volume;

/**
 * Pure Domain Service responsible for calculating biological & physical compatibility.
 * Contains ZERO framework or database dependencies.
 */
final class CompatibilityEngine
{
    /**
     * @param GlassSize $glass
     * @param list<array{plant: Plant, quantity: int}> $plants
     * @param list<array{stone: Stone, quantity: int}> $stones
     * @param list<array{figure: Figure, quantity: int}> $figures
     * @param list<CompatibilityRule> $dynamicRules
     */
    public function validate(
        GlassSize $glass,
        array $plants,
        array $stones = [],
        array $figures = [],
        array $dynamicRules = []
    ): CompatibilityResult {
        $violations = [];

        // 1. Calculate Plant Capacity
        $totalPlantCount = 0;
        foreach ($plants as $item) {
            $totalPlantCount += $item['quantity'];
        }

        if ($totalPlantCount > $glass->maxPlantCapacity) {
            $violations[] = new CompatibilityViolation(
                ruleKey: 'MAX_PLANT_CAPACITY_EXCEEDED',
                message: "ظرف شیشه‌ای انتخابی حداکثر گنجایش {$glass->maxPlantCapacity} گیاه را دارد (تعداد انتخابی: {$totalPlantCount}).",
                severity: 'ERROR',
                componentId: $glass->id,
                componentType: 'glass_size'
            );
        }

        // 2. Closed Ecosystem compatibility
        if ($glass->isClosedEcosystem) {
            foreach ($plants as $item) {
                $plant = $item['plant'];
                if (!$plant->toleratesClosedGlass) {
                    $violations[] = new CompatibilityViolation(
                        ruleKey: 'CLOSED_ECOSYSTEM_INCOMPATIBLE',
                        message: "گیاه «{$plant->name}» به دلیل نیاز به گردش مداوم هوا، در تراریوم دربسته قابل نگهداری نیست.",
                        severity: 'ERROR',
                        componentId: $plant->id,
                        componentType: 'plant'
                    );
                }
            }
        }

        // 3. Moisture Level Conflict (Ecological Harmony)
        $hasLowMoisture = false;
        $hasHighMoisture = false;
        foreach ($plants as $item) {
            $plant = $item['plant'];
            if ($plant->moistureLevel === MoistureLevel::LOW) {
                $hasLowMoisture = true;
            }
            if ($plant->moistureLevel === MoistureLevel::HIGH) {
                $hasHighMoisture = true;
            }
        }

        if ($hasLowMoisture && $hasHighMoisture) {
            $violations[] = new CompatibilityViolation(
                ruleKey: 'MOISTURE_CONFLICT',
                message: "ترکیب گیاهان با نیاز آبی کم (خشک/کاکتوس) و نیاز رطوبتی بالا (مرطوب/سرخس) در یک محفظه باعث پوسیدگی یا خشکی یکی از گونه‌ها خواهد شد.",
                severity: 'ERROR'
            );
        }

        // 4. Physical Volume Calculation
        $totalOccupiedMl = 0;
        foreach ($plants as $item) {
            $totalOccupiedMl += ($item['plant']->volumeOccupancy->milliliters * $item['quantity']);
        }
        foreach ($stones as $item) {
            $totalOccupiedMl += ($item['stone']->volumePerUnit->milliliters * $item['quantity']);
        }
        foreach ($figures as $item) {
            $totalOccupiedMl += ($item['figure']->volumeOccupancy->milliliters * $item['quantity']);
        }

        $usableMl = $glass->usableVolume->milliliters;
        $occupiedVolume = Volume::fromMl($totalOccupiedMl);

        if ($totalOccupiedMl > $usableMl) {
            $shortageMl = $totalOccupiedMl - $usableMl;
            $violations[] = new CompatibilityViolation(
                ruleKey: 'VOLUME_EXCEEDED',
                message: "مجموع حجم گیاهان، بستر سنگ و فیگورها فراتر از حجم مفید ظرف است (کسری فضا: {$shortageMl} میلی‌لیتر).",
                severity: 'ERROR',
                componentId: $glass->id,
                componentType: 'glass_size'
            );
        }

        // 5. Dynamic Rules Evaluation
        foreach ($dynamicRules as $rule) {
            if (!$rule->isCompatible) {
                $matches = $this->evaluateRuleMatch($rule, $glass, $plants, $stones, $figures);
                if ($matches) {
                    $violations[] = new CompatibilityViolation(
                        ruleKey: $rule->ruleType,
                        message: $rule->reasonMessage,
                        severity: 'ERROR',
                        componentId: $rule->sourceId,
                        componentType: $rule->sourceType
                    );
                }
            }
        }

        $remainingMl = max(0, $usableMl - $totalOccupiedMl);

        if (count($violations) > 0) {
            return CompatibilityResult::failure(
                violations: $violations,
                usableVolume: $glass->usableVolume,
                occupiedVolume: $occupiedVolume,
                remainingVolume: Volume::fromMl($remainingMl),
                currentPlantCount: $totalPlantCount,
                maxPlantCapacity: $glass->maxPlantCapacity
            );
        }

        return CompatibilityResult::success(
            usableVolume: $glass->usableVolume,
            occupiedVolume: $occupiedVolume,
            remainingVolume: Volume::fromMl($remainingMl),
            currentPlantCount: $totalPlantCount,
            maxPlantCapacity: $glass->maxPlantCapacity
        );
    }

    /**
     * @param CompatibilityRule $rule
     * @param GlassSize $glass
     * @param list<array{plant: Plant, quantity: int}> $plants
     * @param list<array{stone: Stone, quantity: int}> $stones
     * @param list<array{figure: Figure, quantity: int}> $figures
     */
    private function evaluateRuleMatch(
        CompatibilityRule $rule,
        GlassSize $glass,
        array $plants,
        array $stones,
        array $figures
    ): bool {
        // e.g. rule: PLANT_GLASS incompatibility
        if ($rule->sourceType === 'plant' && $rule->targetType === 'glass_size') {
            if ($rule->targetId === null || $rule->targetId === $glass->id) {
                foreach ($plants as $item) {
                    if ($rule->sourceId === null || $rule->sourceId === $item['plant']->id) {
                        return true;
                    }
                }
            }
        }

        // e.g. rule: PLANT_PLANT incompatibility
        if ($rule->sourceType === 'plant' && $rule->targetType === 'plant') {
            $hasSource = false;
            $hasTarget = false;
            foreach ($plants as $item) {
                if ($item['plant']->id === $rule->sourceId) {
                    $hasSource = true;
                }
                if ($item['plant']->id === $rule->targetId) {
                    $hasTarget = true;
                }
            }
            if ($hasSource && $hasTarget) {
                return true;
            }
        }

        return false;
    }
}

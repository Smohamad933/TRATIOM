<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Configurator;

use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Domain\Compatibility\CompatibilityEngine;
use Terrarium\Domain\Compatibility\CompatibilityViolation;
use Terrarium\Domain\Configurator\Configuration;
use Terrarium\Domain\Pricing\PriceBreakdown;
use Terrarium\Domain\Pricing\PricingEngine;
use Terrarium\Domain\Common\Money;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Repositories\CatalogRepository;

/**
 * Resolves catalog ids to domain objects, runs the Compatibility & Pricing engines and checks stock.
 */
final class ValidateConfigurationUseCase
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CompatibilityEngine $engine = new CompatibilityEngine(),
        private readonly PricingEngine $pricing = new PricingEngine(),
        private readonly int $shippingFlatRate = 0
    ) {}

    /**
     * @return array{configuration: Configuration, breakdown: PriceBreakdown, violations: list<CompatibilityViolation>, is_valid: bool, result: \Terrarium\Domain\Compatibility\CompatibilityResult}
     */
    public function execute(ValidateConfigurationInput $input, ?string $userId = null): array
    {
        $glassRow = $this->catalog->findRow('glass_size', $input->glassSizeId);
        if ($glassRow === null || !$glassRow['is_active']) {
            throw new ValidationException('ظرف شیشه‌ای انتخاب‌شده موجود نیست.', ['field' => 'glass_size_id']);
        }
        $glass = $this->catalog->toGlass($glassRow);

        $stockViolations = [];
        if ($glassRow['stock_quantity'] < 1) {
            $stockViolations[] = $this->outOfStock($glassRow['name'], $glass->id, 'glass_size');
        }

        $resolve = function (string $type, array $items, callable $hydrate, string $key) use (&$stockViolations): array {
            $rows = $this->catalog->findRows($type, array_column($items, 'id'));
            $out = [];
            foreach ($items as $item) {
                $row = $rows[$item['id']] ?? null;
                if ($row === null || !$row['is_active']) {
                    throw new ValidationException('یکی از اقلام انتخاب‌شده دیگر موجود نیست. لطفاً صفحه را بازخوانی کنید.', ['id' => $item['id'], 'type' => $type]);
                }
                if ($row['stock_quantity'] < $item['quantity']) {
                    $stockViolations[] = $this->outOfStock($row['name'], $row['id'], $type, $row['stock_quantity']);
                }
                $out[] = [$key => $hydrate($row), 'quantity' => $item['quantity']];
            }
            return $out;
        };

        $plants = $resolve('plant', $input->plants, [$this->catalog, 'toPlant'], 'plant');
        $stones = $resolve('stone', $input->stones, [$this->catalog, 'toStone'], 'stone');
        $figures = $resolve('figure', $input->figures, [$this->catalog, 'toFigure'], 'figure');

        $result = $this->engine->validate($glass, $plants, $stones, $figures, $this->catalog->activeRules());
        $shipping = Money::fromCents($this->shippingFlatRate, $glass->price->currency);
        $breakdown = $this->pricing->calculate($glass, $plants, $stones, $figures, null, $shipping);

        $violations = array_merge($result->violations, $stockViolations);
        $isValid = $result->isValid && $stockViolations === [] && $plants !== [];
        if ($plants === []) {
            $violations[] = new CompatibilityViolation('NO_PLANTS', 'حداقل یک گیاه انتخاب کنید.', 'ERROR');
        }

        $configuration = new Configuration(
            id: Database::uuid(),
            userId: $userId,
            glassSize: $glass,
            plants: $plants,
            stones: $stones,
            figures: $figures,
            calculatedPrice: $breakdown->total,
            isValid: $isValid,
            validationResult: $result
        );

        return [
            'configuration' => $configuration,
            'breakdown' => $breakdown,
            'violations' => $violations,
            'is_valid' => $isValid,
            'result' => $result,
        ];
    }

    private function outOfStock(string $name, string $id, string $type, int $left = 0): CompatibilityViolation
    {
        return new CompatibilityViolation(
            ruleKey: 'OUT_OF_STOCK',
            message: $left > 0 ? "از «{$name}» فقط {$left} عدد موجود است." : "«{$name}» در حال حاضر ناموجود است.",
            severity: 'ERROR',
            componentId: $id,
            componentType: $type
        );
    }

    /** @return array<string, mixed> JSON-friendly representation */
    public static function present(array $out): array
    {
        $r = $out['result'];
        return [
            'is_valid' => $out['is_valid'],
            'violations' => array_map(fn (CompatibilityViolation $v) => [
                'rule' => $v->ruleKey,
                'message' => $v->message,
                'severity' => $v->severity,
                'component_id' => $v->componentId,
                'component_type' => $v->componentType,
            ], $out['violations']),
            'volume' => [
                'usable_ml' => $r->usableVolume->milliliters,
                'occupied_ml' => $r->occupiedVolume->milliliters,
                'remaining_ml' => $r->remainingVolume->milliliters,
            ],
            'plants' => ['count' => $r->currentPlantCount, 'max' => $r->maxPlantCapacity],
            'price' => $out['breakdown']->toArray(),
        ];
    }
}

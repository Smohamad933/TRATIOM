<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Configurator;

use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Compatibility\CompatibilityEngine;
use Terrarium\Domain\Compatibility\CompatibilityResult;

final class ValidateConfigurationUseCase
{
    public function __construct(
        private readonly CompatibilityEngine $engine
    ) {}

    /**
     * @param ValidateConfigurationInput $input
     * @param GlassSize $glass
     * @param list<array{plant: Plant, quantity: int}> $resolvedPlants
     * @param list<array{stone: Stone, quantity: int}> $resolvedStones
     * @param list<array{figure: Figure, quantity: int}> $resolvedFigures
     * @param list<\Terrarium\Domain\Catalog\CompatibilityRule> $rules
     */
    public function execute(
        ValidateConfigurationInput $input,
        GlassSize $glass,
        array $resolvedPlants,
        array $resolvedStones = [],
        array $resolvedFigures = [],
        array $rules = []
    ): CompatibilityResult {
        return $this->engine->validate(
            glass: $glass,
            plants: $resolvedPlants,
            stones: $resolvedStones,
            figures: $resolvedFigures,
            dynamicRules: $rules
        );
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Application\DTO;

final readonly class ValidateConfigurationInput
{
    /**
     * @param string $glassSizeId
     * @param list<array{plant_id: string, quantity: int}> $plants
     * @param list<array{stone_id: string, quantity: int}> $stones
     * @param list<array{figure_id: string, quantity: int}> $figures
     */
    public function __construct(
        public string $glassSizeId,
        public array $plants = [],
        public array $stones = [],
        public array $figures = []
    ) {}
}

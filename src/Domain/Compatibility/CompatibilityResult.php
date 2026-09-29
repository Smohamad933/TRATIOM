<?php

declare(strict_types=1);

namespace Terrarium\Domain\Compatibility;

use Terrarium\Domain\Common\Volume;

final readonly class CompatibilityResult
{
    /**
     * @param list<CompatibilityViolation> $violations
     */
    public function __construct(
        public bool $isValid,
        public array $violations,
        public Volume $usableVolume,
        public Volume $occupiedVolume,
        public Volume $remainingVolume,
        public int $currentPlantCount,
        public int $maxPlantCapacity
    ) {}

    public static function success(
        Volume $usableVolume,
        Volume $occupiedVolume,
        Volume $remainingVolume,
        int $currentPlantCount,
        int $maxPlantCapacity
    ): self {
        return new self(
            isValid: true,
            violations: [],
            usableVolume: $usableVolume,
            occupiedVolume: $occupiedVolume,
            remainingVolume: $remainingVolume,
            currentPlantCount: $currentPlantCount,
            maxPlantCapacity: $maxPlantCapacity
        );
    }

    /**
     * @param list<CompatibilityViolation> $violations
     */
    public static function failure(
        array $violations,
        Volume $usableVolume,
        Volume $occupiedVolume,
        Volume $remainingVolume,
        int $currentPlantCount,
        int $maxPlantCapacity
    ): self {
        return new self(
            isValid: false,
            violations: $violations,
            usableVolume: $usableVolume,
            occupiedVolume: $occupiedVolume,
            remainingVolume: $remainingVolume,
            currentPlantCount: $currentPlantCount,
            maxPlantCapacity: $maxPlantCapacity
        );
    }
}

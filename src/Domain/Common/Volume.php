<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

use InvalidArgumentException;

/**
 * Value Object representing an immutable physical volume in milliliters (ml / cm^3).
 */
final readonly class Volume
{
    public function __construct(
        public int $milliliters
    ) {
        if ($this->milliliters < 0) {
            throw new InvalidArgumentException("Volume cannot be negative: {$this->milliliters} ml");
        }
    }

    public static function fromMl(int $ml): self
    {
        return new self($ml);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->milliliters + $other->milliliters);
    }

    public function subtract(self $other): self
    {
        $res = $this->milliliters - $other->milliliters;
        return new self(max(0, $res));
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->milliliters > $other->milliliters;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->milliliters >= $other->milliliters;
    }
}

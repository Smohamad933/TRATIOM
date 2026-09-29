<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

use InvalidArgumentException;

/**
 * Value Object representing an immutable monetary amount.
 * Strictly avoids floating point inaccuracies by storing values as integer units (cents/rials).
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency = 'IRR'
    ) {
        if ($this->amount < 0) {
            throw new InvalidArgumentException("Money amount cannot be negative: {$this->amount}");
        }
    }

    public static function zero(string $currency = 'IRR'): self
    {
        return new self(0, $currency);
    }

    public static function fromCents(int $amount, string $currency = 'IRR'): self
    {
        return new self($amount, $currency);
    }

    public function add(self $other): self
    {
        $this->ensureSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->ensureSameCurrency($other);
        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw new InvalidArgumentException("Subtraction resulted in a negative amount.");
        }
        return new self($result, $this->currency);
    }

    public function multiply(float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException("Multiplier cannot be negative.");
        }
        return new self((int) round($this->amount * $multiplier), $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->ensureSameCurrency($other);
        return $this->amount > $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function formatted(): string
    {
        return number_format($this->amount) . ' ' . $this->currency;
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}

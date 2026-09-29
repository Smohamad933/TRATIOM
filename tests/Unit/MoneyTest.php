<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Terrarium\Domain\Common\Money;

final class MoneyTest extends TestCase
{
    public function test_can_create_and_add_money(): void
    {
        $m1 = Money::fromCents(100000, 'IRR');
        $m2 = Money::fromCents(50000, 'IRR');
        $sum = $m1->add($m2);

        $this->assertSame(150000, $sum->amount);
        $this->assertSame('IRR', $sum->currency);
    }

    public function test_cannot_have_negative_money(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCents(-500);
    }

    public function test_cannot_add_different_currencies(): void
    {
        $m1 = Money::fromCents(100, 'IRR');
        $m2 = Money::fromCents(100, 'USD');

        $this->expectException(InvalidArgumentException::class);
        $m1->add($m2);
    }

    public function test_subtraction_preventing_negative(): void
    {
        $m1 = Money::fromCents(500, 'IRR');
        $m2 = Money::fromCents(600, 'IRR');

        $this->expectException(InvalidArgumentException::class);
        $m1->subtract($m2);
    }
}

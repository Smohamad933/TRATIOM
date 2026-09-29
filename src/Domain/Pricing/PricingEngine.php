<?php

declare(strict_types=1);

namespace Terrarium\Domain\Pricing;

use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Common\Money;

/**
 * Pure Domain Service responsible for financial calculation.
 * Strictly uses Money Value Object and integer arithmetic.
 */
final class PricingEngine
{
    /**
     * @param GlassSize $glass
     * @param list<array{plant: Plant, quantity: int}> $plants
     * @param list<array{stone: Stone, quantity: int}> $stones
     * @param list<array{figure: Figure, quantity: int}> $figures
     * @param Money|null $discount
     * @param Money|null $shipping
     */
    public function calculate(
        GlassSize $glass,
        array $plants = [],
        array $stones = [],
        array $figures = [],
        ?Money $discount = null,
        ?Money $shipping = null
    ): PriceBreakdown {
        $currency = $glass->price->currency;
        $discount = $discount ?? Money::zero($currency);
        $shipping = $shipping ?? Money::zero($currency);

        $glassPrice = $glass->price;

        $plantsTotal = Money::zero($currency);
        foreach ($plants as $item) {
            $itemTotal = $item['plant']->price->multiply((float) $item['quantity']);
            $plantsTotal = $plantsTotal->add($itemTotal);
        }

        $stonesTotal = Money::zero($currency);
        foreach ($stones as $item) {
            $itemTotal = $item['stone']->price->multiply((float) $item['quantity']);
            $stonesTotal = $stonesTotal->add($itemTotal);
        }

        $figuresTotal = Money::zero($currency);
        foreach ($figures as $item) {
            $itemTotal = $item['figure']->price->multiply((float) $item['quantity']);
            $figuresTotal = $figuresTotal->add($itemTotal);
        }

        $subtotal = $glassPrice
            ->add($plantsTotal)
            ->add($stonesTotal)
            ->add($figuresTotal);

        // Apply discount without allowing subtotal to become negative
        $effectiveDiscount = $discount->isGreaterThan($subtotal) ? $subtotal : $discount;
        $totalAfterDiscount = $subtotal->subtract($effectiveDiscount);
        $finalTotal = $totalAfterDiscount->add($shipping);

        return new PriceBreakdown(
            glassPrice: $glassPrice,
            plantsTotal: $plantsTotal,
            stonesTotal: $stonesTotal,
            figuresTotal: $figuresTotal,
            subtotal: $subtotal,
            discount: $effectiveDiscount,
            shipping: $shipping,
            total: $finalTotal
        );
    }
}

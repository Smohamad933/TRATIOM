<?php

declare(strict_types=1);

namespace Terrarium\Domain\Pricing;

use Terrarium\Domain\Common\Money;

final readonly class PriceBreakdown
{
    public function __construct(
        public Money $glassPrice,
        public Money $plantsTotal,
        public Money $stonesTotal,
        public Money $figuresTotal,
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $total
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'glass_price_cents' => $this->glassPrice->amount,
            'plants_total_cents' => $this->plantsTotal->amount,
            'stones_total_cents' => $this->stonesTotal->amount,
            'figures_total_cents' => $this->figuresTotal->amount,
            'subtotal_cents' => $this->subtotal->amount,
            'discount_cents' => $this->discount->amount,
            'shipping_cents' => $this->shipping->amount,
            'total_cents' => $this->total->amount,
            'currency' => $this->total->currency,
            'formatted_total' => $this->total->formatted(),
        ];
    }
}

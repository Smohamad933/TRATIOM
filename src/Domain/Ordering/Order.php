<?php

declare(strict_types=1);

namespace Terrarium\Domain\Ordering;

use DomainException;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\OrderStatus;

final class Order
{
    /**
     * @param list<OrderItem> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $orderNumber,
        private OrderStatus $status,
        public readonly Money $totalPrice,
        public readonly Money $discount,
        public readonly Money $shipping,
        public array $items = []
    ) {}

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function transitionTo(OrderStatus $newStatus): void
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw new DomainException(
                "Cannot transition order {$this->orderNumber} from status '{$this->status->value}' to '{$newStatus->value}'."
            );
        }
        $this->status = $newStatus;
    }

    public function markAsPaid(): void
    {
        $this->transitionTo(OrderStatus::PAID);
    }

    public function markAsProcessing(): void
    {
        $this->transitionTo(OrderStatus::PROCESSING);
    }

    public function markAsCompleted(): void
    {
        $this->transitionTo(OrderStatus::COMPLETED);
    }

    public function cancel(): void
    {
        $this->transitionTo(OrderStatus::CANCELLED);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use DomainException;
use PHPUnit\Framework\TestCase;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Ordering\Order;

final class OrderStateMachineTest extends TestCase
{
    public function test_valid_transitions_lifecycle(): void
    {
        $order = new Order(
            id: 'ord-1',
            userId: 'user-1',
            orderNumber: 'TRM-2026-001',
            status: OrderStatus::DRAFT,
            totalPrice: Money::fromCents(5000000),
            discount: Money::zero(),
            shipping: Money::zero()
        );

        $order->transitionTo(OrderStatus::PENDING_PAYMENT);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status());

        $order->markAsPaid();
        $this->assertSame(OrderStatus::PAID, $order->status());

        $order->markAsProcessing();
        $this->assertSame(OrderStatus::PROCESSING, $order->status());

        $order->markAsCompleted();
        $this->assertSame(OrderStatus::COMPLETED, $order->status());
    }

    public function test_illegal_transition_throws_domain_exception(): void
    {
        $order = new Order(
            id: 'ord-2',
            userId: 'user-1',
            orderNumber: 'TRM-2026-002',
            status: OrderStatus::COMPLETED,
            totalPrice: Money::fromCents(5000000),
            discount: Money::zero(),
            shipping: Money::zero()
        );

        // Cannot transition from COMPLETED to PENDING_PAYMENT
        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::PENDING_PAYMENT);
    }
}

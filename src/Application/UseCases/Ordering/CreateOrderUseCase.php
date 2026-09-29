<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Ordering;

use DomainException;
use Terrarium\Application\DTO\CreateOrderInput;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Configurator\Configuration;
use Terrarium\Domain\Ordering\Order;
use Terrarium\Domain\Ordering\OrderItem;

final class CreateOrderUseCase
{
    public function execute(CreateOrderInput $input, Configuration $configuration): Order
    {
        if (!$configuration->isValid) {
            throw new DomainException("Cannot create an order from an invalid configuration.");
        }

        $orderId = 'ord_' . bin2hex(random_bytes(10));
        $orderNumber = 'TRM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // Create immutable snapshot
        $snapshot = $configuration->createSnapshot();

        $orderItem = new OrderItem(
            id: 'item_' . bin2hex(random_bytes(10)),
            orderId: $orderId,
            configurationId: $configuration->id,
            unitPrice: $configuration->calculatedPrice,
            quantity: 1,
            snapshotData: $snapshot
        );

        return new Order(
            id: $orderId,
            userId: $input->userId,
            orderNumber: $orderNumber,
            status: OrderStatus::PENDING_PAYMENT,
            totalPrice: $configuration->calculatedPrice,
            discount: Money::zero($configuration->calculatedPrice->currency),
            shipping: Money::zero($configuration->calculatedPrice->currency),
            items: [$orderItem]
        );
    }
}

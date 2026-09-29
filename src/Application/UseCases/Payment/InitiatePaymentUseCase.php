<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Payment;

use Terrarium\Domain\Ordering\Order;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class InitiatePaymentUseCase
{
    public function execute(
        Order $order,
        PaymentGatewayInterface $gateway,
        string $callbackUrl
    ): array {
        return $gateway->initiatePayment(
            orderId: $order->id,
            amount: $order->totalPrice,
            callbackUrl: $callbackUrl,
            metadata: [
                'order_number' => $order->orderNumber,
                'user_id' => $order->userId,
            ]
        );
    }
}

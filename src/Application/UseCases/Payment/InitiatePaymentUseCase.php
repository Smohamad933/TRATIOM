<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Payment;

use Terrarium\Domain\Ordering\Order;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class InitiatePaymentUseCase
{
    /**
     * @param array<string, mixed> $extraMetadata
     * @return array{payment_url: string, authority_or_token: string}
     */
    public function execute(
        Order $order,
        PaymentGatewayInterface $gateway,
        string $callbackUrl,
        array $extraMetadata = []
    ): array {
        return $gateway->initiatePayment(
            orderId: $order->id,
            amount: $order->totalPrice,
            callbackUrl: $callbackUrl,
            metadata: array_merge([
                'order_number' => $order->orderNumber,
                'user_id' => $order->userId,
                'description' => 'خرید تراریوم - سفارش ' . $order->orderNumber,
            ], $extraMetadata)
        );
    }
}

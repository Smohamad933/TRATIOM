<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Payment;

use Terrarium\Domain\Ordering\Order;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class VerifyPaymentUseCase
{
    /** @return array{success: bool, reference_id: ?string, raw_response: array<string, mixed>} */
    public function execute(
        Order $order,
        PaymentGatewayInterface $gateway,
        string $authorityOrToken
    ): array {
        $result = $gateway->verifyPayment($authorityOrToken, $order->totalPrice, $order->id);
        if ($result['success']) {
            $order->markAsPaid();
        }
        return $result;
    }
}

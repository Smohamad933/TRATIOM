<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret = '',
        private readonly string $currency = 'USD'
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'stripe';
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $sessionId = 'cs_' . bin2hex(random_bytes(16));
        return [
            'payment_url' => 'https://checkout.stripe.com/c/pay/' . $sessionId,
            'authority_or_token' => $sessionId,
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount): array
    {
        return [
            'success' => true,
            'reference_id' => 'ch_' . bin2hex(random_bytes(12)),
            'raw_response' => [
                'status' => 'paid',
                'session_id' => $authorityOrToken,
            ],
        ];
    }
}

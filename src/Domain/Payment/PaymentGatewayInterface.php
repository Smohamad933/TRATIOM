<?php

declare(strict_types=1);

namespace Terrarium\Domain\Payment;

use Terrarium\Domain\Common\Money;

interface PaymentGatewayInterface
{
    public function getGatewayIdentifier(): string;

    /**
     * Initiates payment with provider and returns payment redirect URL or token.
     * @param string $orderId
     * @param Money $amount
     * @param string $callbackUrl
     * @param array<string, mixed> $metadata
     * @return array{payment_url: string, authority_or_token: string}
     */
    public function initiatePayment(
        string $orderId,
        Money $amount,
        string $callbackUrl,
        array $metadata = []
    ): array;

    /**
     * Verifies payment result server-to-server.
     * @param string $authorityOrToken
     * @param Money $expectedAmount
     * @return array{success: bool, reference_id: ?string, raw_response: array<string, mixed>}
     */
    public function verifyPayment(
        string $authorityOrToken,
        Money $expectedAmount
    ): array;
}

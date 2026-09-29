<?php

declare(strict_types=1);

namespace Terrarium\Domain\Payment;

use Terrarium\Domain\Common\Money;

interface PaymentGatewayInterface
{
    public function getGatewayIdentifier(): string;

    /** True when the required credentials are present. */
    public function isConfigured(): bool;

    /**
     * Registers the payment with the provider and returns the URL the customer must be redirected to.
     * @param array<string, mixed> $metadata  (order_number, mobile, description, ...)
     * @return array{payment_url: string, authority_or_token: string}
     * @throws PaymentGatewayException
     */
    public function initiatePayment(
        string $orderId,
        Money $amount,
        string $callbackUrl,
        array $metadata = []
    ): array;

    /**
     * Extracts the payment token/authority from the provider's callback request (query + POST params).
     * @param array<string, mixed> $params
     * @return array{token: ?string, provider_reports_success: bool}
     */
    public function parseCallback(array $params): array;

    /**
     * Verifies the payment server-to-server. MUST check the amount against $expectedAmount.
     * @return array{success: bool, reference_id: ?string, raw_response: array<string, mixed>}
     * @throws PaymentGatewayException on transport/provider errors
     */
    public function verifyPayment(
        string $authorityOrToken,
        Money $expectedAmount,
        string $orderId = ''
    ): array;
}

<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class IDPayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = ''
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'idpay';
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $targetCallback = !empty($callbackUrl) ? $callbackUrl : $this->callbackUrl;
        $id = bin2hex(random_bytes(16));
        return [
            'payment_url' => 'https://api.idpay.ir/v1.1/payment/verify',
            'authority_or_token' => $id,
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount): array
    {
        return [
            'success' => true,
            'reference_id' => 'ref_idpay_' . mt_rand(100000, 999999),
            'raw_response' => [
                'status' => 100,
                'track_id' => $authorityOrToken,
            ],
        ];
    }
}

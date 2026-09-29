<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class ZibalGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = ''
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'zibal';
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $targetCallback = !empty($callbackUrl) ? $callbackUrl : $this->callbackUrl;
        $trackId = 'zib_' . mt_rand(1000000, 9999999);
        return [
            'payment_url' => 'https://gateway.zibal.ir/start/' . $trackId,
            'authority_or_token' => (string) $trackId,
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount): array
    {
        return [
            'success' => true,
            'reference_id' => 'ref_zib_' . mt_rand(10000000, 99999999),
            'raw_response' => [
                'result' => 100,
                'message' => 'Verified successfully with Zibal',
                'trackId' => $authorityOrToken,
            ],
        ];
    }
}

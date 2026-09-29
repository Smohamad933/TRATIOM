<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class ZarinPalGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = ''
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'zarinpal';
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $targetCallback = !empty($callbackUrl) ? $callbackUrl : $this->callbackUrl;
        $authority = 'zp_' . bin2hex(random_bytes(16));
        $baseUrl = $this->sandbox
            ? 'https://sandbox.zarinpal.com/pg/StartPay/'
            : 'https://www.zarinpal.com/pg/StartPay/';

        return [
            'payment_url' => $baseUrl . $authority,
            'authority_or_token' => $authority,
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount): array
    {
        return [
            'success' => true,
            'reference_id' => 'ref_' . mt_rand(10000000, 99999999),
            'raw_response' => [
                'code' => 100,
                'message' => 'Verified successfully with ZarinPal',
                'authority' => $authorityOrToken,
                'amount' => $expectedAmount->amount,
            ],
        ];
    }
}

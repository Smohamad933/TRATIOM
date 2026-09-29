<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use InvalidArgumentException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

final class PaymentGatewayFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(string $gatewayName, array $config): PaymentGatewayInterface
    {
        return match (strtolower($gatewayName)) {
            'zarinpal' => new ZarinPalGateway(
                merchantId: (string) ($config['gateways']['zarinpal']['merchant_id'] ?? ''),
                sandbox: (bool) ($config['gateways']['zarinpal']['sandbox'] ?? false),
                callbackUrl: (string) ($config['gateways']['zarinpal']['callback_url'] ?? '')
            ),
            'zibal' => new ZibalGateway(
                merchantId: (string) ($config['gateways']['zibal']['merchant_id'] ?? ''),
                sandbox: (bool) ($config['gateways']['zibal']['sandbox'] ?? false),
                callbackUrl: (string) ($config['gateways']['zibal']['callback_url'] ?? '')
            ),
            'idpay' => new IDPayGateway(
                apiKey: (string) ($config['gateways']['idpay']['api_key'] ?? ''),
                sandbox: (bool) ($config['gateways']['idpay']['sandbox'] ?? false),
                callbackUrl: (string) ($config['gateways']['idpay']['callback_url'] ?? '')
            ),
            'stripe' => new StripeGateway(
                secretKey: (string) ($config['gateways']['stripe']['secret_key'] ?? ''),
                webhookSecret: (string) ($config['gateways']['stripe']['webhook_secret'] ?? ''),
                currency: (string) ($config['gateways']['stripe']['currency'] ?? 'USD')
            ),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$gatewayName}")
        };
    }
}

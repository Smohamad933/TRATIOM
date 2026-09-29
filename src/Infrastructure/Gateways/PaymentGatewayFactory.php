<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use InvalidArgumentException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Http\HttpClient;

final class PaymentGatewayFactory
{
    public const SUPPORTED = ['zarinpal', 'zibal', 'idpay', 'stripe'];

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * @param array<string, mixed> $config contents of config/payment.php
     */
    public function create(string $gatewayName, array $config): PaymentGatewayInterface
    {
        $g = $config['gateways'] ?? [];
        return match (strtolower($gatewayName)) {
            'zarinpal' => new ZarinPalGateway(
                merchantId: (string) ($g['zarinpal']['merchant_id'] ?? ''),
                sandbox: (bool) ($g['zarinpal']['sandbox'] ?? false),
                callbackUrl: (string) ($g['zarinpal']['callback_url'] ?? ''),
                http: $this->http
            ),
            'zibal' => new ZibalGateway(
                merchantId: (string) ($g['zibal']['merchant_id'] ?? ''),
                sandbox: (bool) ($g['zibal']['sandbox'] ?? false),
                callbackUrl: (string) ($g['zibal']['callback_url'] ?? ''),
                http: $this->http
            ),
            'idpay' => new IDPayGateway(
                apiKey: (string) ($g['idpay']['api_key'] ?? ''),
                sandbox: (bool) ($g['idpay']['sandbox'] ?? false),
                callbackUrl: (string) ($g['idpay']['callback_url'] ?? ''),
                http: $this->http
            ),
            'stripe' => new StripeGateway(
                secretKey: (string) ($g['stripe']['secret_key'] ?? ''),
                webhookSecret: (string) ($g['stripe']['webhook_secret'] ?? ''),
                currency: (string) ($g['stripe']['currency'] ?? 'USD'),
                callbackUrl: (string) ($g['stripe']['callback_url'] ?? ''),
                http: $this->http
            ),
            default => throw new InvalidArgumentException("Unsupported payment gateway: {$gatewayName}")
        };
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Http\HttpClient;
use Throwable;

/**
 * Stripe Checkout Sessions — https://docs.stripe.com/api/checkout/sessions
 * Only usable when the store currency (APP_CURRENCY) matches STRIPE_CURRENCY (Stripe does not support IRR).
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private const BASE = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret = '',
        private readonly string $currency = 'USD',
        private readonly string $callbackUrl = '',
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->secretKey, 'sk_') && !str_contains($this->secretKey, 'YOUR_');
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        if (strtoupper($amount->currency) !== strtoupper($this->currency)) {
            throw new PaymentGatewayException("Stripe cannot charge {$amount->currency}; store currency must be {$this->currency}.");
        }
        $cb = $callbackUrl !== '' ? $callbackUrl : $this->callbackUrl;
        $sep = str_contains($cb, '?') ? '&' : '?';

        $res = $this->call('POST', '/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $orderId,
            'success_url' => $cb . $sep . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cb . $sep . 'session_id={CHECKOUT_SESSION_ID}&cancelled=1',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($this->currency),
                    'unit_amount' => $amount->amount,
                    'product_data' => ['name' => 'Terrarium order ' . ($metadata['order_number'] ?? $orderId)],
                ],
            ]],
            'metadata' => ['order_id' => $orderId, 'order_number' => (string) ($metadata['order_number'] ?? '')],
        ]);

        if (empty($res['id']) || empty($res['url'])) {
            throw new PaymentGatewayException('Stripe session creation failed: ' . ($res['error']['message'] ?? 'unknown'), $res);
        }
        return ['payment_url' => (string) $res['url'], 'authority_or_token' => (string) $res['id']];
    }

    public function parseCallback(array $params): array
    {
        return [
            'token' => isset($params['session_id']) ? (string) $params['session_id'] : null,
            'provider_reports_success' => !isset($params['cancelled']),
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $authorityOrToken)) {
            return ['success' => false, 'reference_id' => null, 'raw_response' => ['error' => 'invalid session id']];
        }
        $res = $this->call('GET', '/checkout/sessions/' . $authorityOrToken);
        $ok = ($res['payment_status'] ?? '') === 'paid'
            && (int) ($res['amount_total'] ?? -1) === $expectedAmount->amount
            && strtoupper((string) ($res['currency'] ?? '')) === strtoupper($expectedAmount->currency);

        return [
            'success' => $ok,
            'reference_id' => $ok ? (string) ($res['payment_intent'] ?? $authorityOrToken) : null,
            'raw_response' => array_intersect_key($res, array_flip(['id', 'payment_status', 'status', 'amount_total', 'currency', 'payment_intent'])),
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?array $form = null): array
    {
        try {
            $response = $this->http->request($method, self::BASE . $path, $form, ['Authorization' => 'Bearer ' . $this->secretKey], true);
        } catch (Throwable $e) {
            throw new PaymentGatewayException('Stripe connection error: ' . $e->getMessage());
        }
        if (!is_array($response['json'])) {
            throw new PaymentGatewayException("Stripe returned an invalid response (HTTP {$response['status']}).");
        }
        return $response['json'];
    }
}

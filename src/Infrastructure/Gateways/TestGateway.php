<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayInterface;

/**
 * Local simulation gateway for development/staging. NEVER registered when APP_ENV=production.
 * Redirects to /api/v1/payments/test-gateway where the tester chooses success or failure.
 */
final class TestGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly string $appUrl, private readonly string $secret) {}

    public function getGatewayIdentifier(): string
    {
        return 'test';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $token = 'test_' . bin2hex(random_bytes(12));
        $sig = $this->sign($token, $amount->amount);
        $query = http_build_query([
            'token' => $token,
            'amount' => $amount->amount,
            'sig' => $sig,
            'order' => $metadata['order_number'] ?? $orderId,
            'callback' => $callbackUrl,
        ]);
        return ['payment_url' => $this->appUrl . '/api/v1/payments/test-gateway?' . $query, 'authority_or_token' => $token];
    }

    public function parseCallback(array $params): array
    {
        return [
            'token' => isset($params['token']) ? (string) $params['token'] : null,
            'provider_reports_success' => ($params['result'] ?? '') === 'success'
                && hash_equals($this->sign((string) ($params['token'] ?? ''), (int) ($params['amount'] ?? 0)), (string) ($params['sig'] ?? '')),
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        // The callback already validated the HMAC signature; verification simply confirms the token format.
        $ok = str_starts_with($authorityOrToken, 'test_');
        return [
            'success' => $ok,
            'reference_id' => $ok ? 'TEST-' . strtoupper(substr(hash('sha256', $authorityOrToken), 0, 10)) : null,
            'raw_response' => ['simulated' => true],
        ];
    }

    public function sign(string $token, int $amount): string
    {
        return hash_hmac('sha256', $token . '|' . $amount, $this->secret);
    }
}

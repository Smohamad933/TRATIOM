<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Http\HttpClient;
use Throwable;

/**
 * IDPay API v1.1 — https://idpay.ir/web-service/v1.1/
 */
final class IDPayGateway implements PaymentGatewayInterface
{
    private const BASE = 'https://api.idpay.ir/v1.1';

    public function __construct(
        private readonly string $apiKey,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = '',
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'idpay';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && !str_starts_with($this->apiKey, 'YOUR_');
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $res = $this->call('/payment', array_filter([
            'order_id' => $orderId,
            'amount' => $amount->amount, // Rials
            'phone' => $metadata['mobile'] ?? null,
            'desc' => $metadata['description'] ?? ('سفارش ' . ($metadata['order_number'] ?? $orderId)),
            'callback' => $callbackUrl !== '' ? $callbackUrl : $this->callbackUrl,
        ], fn ($v) => $v !== null));

        if (empty($res['id']) || empty($res['link'])) {
            throw new PaymentGatewayException('IDPay payment request rejected: ' . ($res['error_message'] ?? 'unknown'), $res);
        }

        return [
            'payment_url' => (string) $res['link'],
            'authority_or_token' => (string) $res['id'],
        ];
    }

    public function parseCallback(array $params): array
    {
        return [
            'token' => isset($params['id']) ? (string) $params['id'] : null,
            // status 10 = "waiting for merchant verification"
            'provider_reports_success' => (int) ($params['status'] ?? 0) === 10,
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        $payload = ['id' => $authorityOrToken];
        if ($orderId !== '') {
            $payload['order_id'] = $orderId;
        }
        $res = $this->call('/payment/verify', $payload);
        $status = (int) ($res['status'] ?? 0);

        // 100 = verified, 101 = already verified
        $ok = in_array($status, [100, 101], true);
        if ($ok && isset($res['amount']) && (int) $res['amount'] !== $expectedAmount->amount) {
            $ok = false;
            $res['_amount_mismatch'] = true;
        }

        return [
            'success' => $ok,
            'reference_id' => $ok ? (string) ($res['track_id'] ?? $res['payment']['track_id'] ?? '') : null,
            'raw_response' => $res,
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $path, array $payload): array
    {
        try {
            $response = $this->http->postJson(self::BASE . $path, $payload, [
                'X-API-KEY' => $this->apiKey,
                'X-SANDBOX' => $this->sandbox ? '1' : '0',
            ]);
        } catch (Throwable $e) {
            throw new PaymentGatewayException('IDPay connection error: ' . $e->getMessage());
        }
        if (!is_array($response['json'])) {
            throw new PaymentGatewayException("IDPay returned an invalid response (HTTP {$response['status']}).", ['body' => mb_substr($response['body'], 0, 500)]);
        }
        return $response['json'];
    }
}

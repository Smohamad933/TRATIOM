<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Http\HttpClient;
use Throwable;

/**
 * Zibal payment gateway v1 — https://help.zibal.ir/IPG/API/
 * Sandbox mode uses the public test merchant "zibal".
 */
final class ZibalGateway implements PaymentGatewayInterface
{
    private const BASE = 'https://gateway.zibal.ir';

    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = '',
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'zibal';
    }

    public function isConfigured(): bool
    {
        return $this->sandbox || ($this->merchantId !== '' && !str_starts_with($this->merchantId, 'YOUR_'));
    }

    private function merchant(): string
    {
        return $this->sandbox ? 'zibal' : $this->merchantId;
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $res = $this->call('/v1/request', array_filter([
            'merchant' => $this->merchant(),
            'amount' => $amount->amount, // Rials
            'callbackUrl' => $callbackUrl !== '' ? $callbackUrl : $this->callbackUrl,
            'orderId' => (string) ($metadata['order_number'] ?? $orderId),
            'mobile' => $metadata['mobile'] ?? null,
            'description' => $metadata['description'] ?? null,
        ], fn ($v) => $v !== null));

        if ((int) ($res['result'] ?? 0) !== 100 || empty($res['trackId'])) {
            throw new PaymentGatewayException('Zibal payment request rejected: ' . ($res['message'] ?? 'unknown'), $res);
        }
        $trackId = (string) $res['trackId'];

        return [
            'payment_url' => self::BASE . '/start/' . $trackId,
            'authority_or_token' => $trackId,
        ];
    }

    public function parseCallback(array $params): array
    {
        return [
            'token' => isset($params['trackId']) ? (string) $params['trackId'] : null,
            'provider_reports_success' => (string) ($params['success'] ?? '') === '1',
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        $res = $this->call('/v1/verify', ['merchant' => $this->merchant(), 'trackId' => (int) $authorityOrToken]);
        $result = (int) ($res['result'] ?? 0);

        // 100 = verified, 201 = already verified
        $ok = in_array($result, [100, 201], true);
        if ($ok && isset($res['amount']) && (int) $res['amount'] !== $expectedAmount->amount) {
            $ok = false;
            $res['_amount_mismatch'] = true;
        }

        return [
            'success' => $ok,
            'reference_id' => $ok ? (string) ($res['refNumber'] ?? $authorityOrToken) : null,
            'raw_response' => $res,
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $path, array $payload): array
    {
        try {
            $response = $this->http->postJson(self::BASE . $path, $payload);
        } catch (Throwable $e) {
            throw new PaymentGatewayException('Zibal connection error: ' . $e->getMessage());
        }
        if (!is_array($response['json'])) {
            throw new PaymentGatewayException("Zibal returned an invalid response (HTTP {$response['status']}).", ['body' => mb_substr($response['body'], 0, 500)]);
        }
        return $response['json'];
    }
}

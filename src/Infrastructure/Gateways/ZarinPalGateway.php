<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Http\HttpClient;
use Throwable;

/**
 * ZarinPal REST API v4 — https://www.zarinpal.com/docs/paymentGateway/
 */
final class ZarinPalGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $merchantId,
        private readonly bool $sandbox = false,
        private readonly string $callbackUrl = '',
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function getGatewayIdentifier(): string
    {
        return 'zarinpal';
    }

    public function isConfigured(): bool
    {
        return $this->merchantId !== '' && !str_starts_with($this->merchantId, 'YOUR_');
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        $payload = [
            'merchant_id' => $this->merchantId,
            'amount' => $amount->amount,
            'currency' => $amount->currency === 'IRT' ? 'IRT' : 'IRR',
            'callback_url' => $callbackUrl !== '' ? $callbackUrl : $this->callbackUrl,
            'description' => (string) ($metadata['description'] ?? ('سفارش ' . ($metadata['order_number'] ?? $orderId))),
            'metadata' => array_filter([
                'mobile' => $metadata['mobile'] ?? null,
                'order_id' => (string) ($metadata['order_number'] ?? $orderId),
            ]),
        ];

        $res = $this->call('/pg/v4/payment/request.json', $payload);
        $code = (int) ($res['data']['code'] ?? 0);
        $authority = (string) ($res['data']['authority'] ?? '');
        if ($code !== 100 || $authority === '') {
            throw new PaymentGatewayException('ZarinPal payment request rejected: ' . $this->errorMessage($res), $res);
        }

        return [
            'payment_url' => $this->base() . '/pg/StartPay/' . $authority,
            'authority_or_token' => $authority,
        ];
    }

    public function parseCallback(array $params): array
    {
        return [
            'token' => isset($params['Authority']) ? (string) $params['Authority'] : null,
            'provider_reports_success' => ($params['Status'] ?? '') === 'OK',
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        $res = $this->call('/pg/v4/payment/verify.json', [
            'merchant_id' => $this->merchantId,
            'amount' => $expectedAmount->amount,
            'authority' => $authorityOrToken,
        ]);
        $code = (int) ($res['data']['code'] ?? 0);

        // 100 = verified now, 101 = already verified earlier (idempotent)
        $ok = in_array($code, [100, 101], true);
        return [
            'success' => $ok,
            'reference_id' => $ok ? (string) ($res['data']['ref_id'] ?? '') : null,
            'raw_response' => $res,
        ];
    }

    /** @return array<string, mixed> */
    private function call(string $path, array $payload): array
    {
        try {
            $response = $this->http->postJson($this->base() . $path, $payload);
        } catch (Throwable $e) {
            throw new PaymentGatewayException('ZarinPal connection error: ' . $e->getMessage());
        }
        $json = is_array($response['json']) ? $response['json'] : [];
        if ($json === []) {
            throw new PaymentGatewayException("ZarinPal returned an invalid response (HTTP {$response['status']}).", ['body' => mb_substr($response['body'], 0, 500)]);
        }
        return $json;
    }

    private function errorMessage(array $res): string
    {
        $errors = $res['errors'] ?? [];
        if (is_array($errors) && isset($errors['message'])) {
            return ($errors['code'] ?? '') . ' ' . $errors['message'];
        }
        return (string) ($res['data']['message'] ?? 'unknown error');
    }
}

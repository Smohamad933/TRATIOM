<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Gateways;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Bale\BaleApiException;
use Terrarium\Infrastructure\Bale\BaleBotClient;
use Terrarium\Infrastructure\Bale\SafirClient;
use Terrarium\Infrastructure\Logging\Logger;

/**
 * Pay with the Bale wallet inside the Bale bot.
 *
 * Flow: checkout → payment_url = https://ble.ir/<bot>?start=pay_<token> (+ a Safir message with the same glass button,
 * delivered even if the user never started the bot) → bot verifies the user's phone → sendInvoice(payload=<token>)
 * → pre_checkout_query is validated → successful_payment → inquireTransaction (server-side) → order PAID.
 */
final class BaleGateway implements PaymentGatewayInterface
{
    /** @var array<string, string> token => wallet transaction id (set by the bot right before verification) */
    private array $transactions = [];

    public function __construct(
        private readonly BaleBotClient $bot,
        private readonly SafirClient $safir,
        private string $botUsername,
        private readonly string $walletToken,
        private readonly ?Logger $logger = null,
        private readonly ?string $cacheFile = null
    ) {}

    /** BALE_BOT_USERNAME may be left empty: it is then read once from getMe and cached. */
    public function botUsername(): string
    {
        if ($this->botUsername !== '') {
            return $this->botUsername;
        }
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            $c = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($c) && ($c['token'] ?? '') === $this->bot->tokenFingerprint() && !empty($c['username'])) {
                return $this->botUsername = (string) $c['username'];
            }
        }
        try {
            $this->botUsername = (string) ($this->bot->getMe()['username'] ?? '');
        } catch (\Throwable $e) {
            $this->logger?->warning('Bale getMe failed', ['error' => $e->getMessage()]);
            return '';
        }
        if ($this->botUsername !== '' && $this->cacheFile !== null) {
            @file_put_contents($this->cacheFile, json_encode(['token' => $this->bot->tokenFingerprint(), 'username' => $this->botUsername]));
        }
        return $this->botUsername;
    }

    public function getGatewayIdentifier(): string
    {
        return 'bale';
    }

    public function isConfigured(): bool
    {
        return $this->bot->isConfigured() && $this->walletToken !== '';
    }

    public function walletToken(): string
    {
        return $this->walletToken;
    }

    public function deepLink(string $startPayload = ''): string
    {
        return 'https://ble.ir/' . rawurlencode($this->botUsername()) . ($startPayload !== '' ? '?start=' . rawurlencode($startPayload) : '');
    }

    public static function newToken(): string
    {
        return 'bale_' . bin2hex(random_bytes(10)); // [A-Za-z0-9_] only, fits the 64-char start parameter
    }

    public function initiatePayment(string $orderId, Money $amount, string $callbackUrl, array $metadata = []): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentGatewayException('Bale payment is not configured (BALE_BOT_TOKEN, BALE_BOT_USERNAME, BALE_WALLET_TOKEN).');
        }
        if ($this->botUsername() === '') {
            throw new PaymentGatewayException('Bale bot username unknown (set BALE_BOT_USERNAME or check the bot token / server internet).');
        }
        $token = self::newToken();
        $link = $this->deepLink('pay_' . $token);

        // Reach the customer in Bale right away (works without /start). Failure is not fatal: the site also opens the link.
        $mobile = (string) ($metadata['mobile'] ?? '');
        if ($mobile !== '' && $this->safir->isConfigured()) {
            $number = (string) ($metadata['order_number'] ?? '');
            $toman = number_format(intdiv($amount->amount, 10));
            $res = $this->safir->sendText($mobile, "🌱 سفارش {$number} ثبت شد.\nمبلغ قابل پرداخت: {$toman} تومان\n\nبرای پرداخت با کیف پول بله، دکمه زیر را بزنید.", [
                [['text' => '💳 پرداخت سفارش', 'url' => $link]],
            ]);
            if (!$res['ok']) {
                $this->logger?->warning('Safir payment message not delivered', ['order' => $number, 'error' => $res['error']]);
            }
        }

        return ['payment_url' => $link, 'authority_or_token' => $token];
    }

    /** Called by the bot with data from the successful_payment update before PaymentCallbackService runs. */
    public function rememberTransaction(string $token, string $transactionId): void
    {
        $this->transactions[$token] = $transactionId;
    }

    public function parseCallback(array $params): array
    {
        $token = isset($params['token']) ? (string) $params['token'] : null;
        return [
            'token' => $token,
            'provider_reports_success' => $token !== null && isset($this->transactions[$token]),
        ];
    }

    public function verifyPayment(string $authorityOrToken, Money $expectedAmount, string $orderId = ''): array
    {
        $tx = $this->transactions[$authorityOrToken] ?? null;
        if ($tx === null) {
            return ['success' => false, 'reference_id' => null, 'raw_response' => ['error' => 'no transaction id']];
        }
        try {
            $t = $this->bot->inquireTransaction($tx);
        } catch (BaleApiException $e) {
            throw new PaymentGatewayException('Bale inquireTransaction failed: ' . $e->getMessage(), $e->response);
        }
        $ok = ($t['status'] ?? '') === 'paid' && (int) ($t['amount'] ?? -1) === $expectedAmount->amount;
        return ['success' => $ok, 'reference_id' => $ok ? $tx : null, 'raw_response' => ['transaction' => $t]];
    }
}

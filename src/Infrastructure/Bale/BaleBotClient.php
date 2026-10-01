<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Bale;

use Terrarium\Infrastructure\Http\HttpClient;

/**
 * Bale bot API (Telegram-compatible) — https://docs.bale.ai
 * All calls: POST https://tapi.bale.ai/bot<token>/<method> with a JSON body.
 */
final class BaleBotClient
{
    public function __construct(
        private readonly string $token,
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $apiBase = 'https://tapi.bale.ai'
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== '' && str_contains($this->token, ':');
    }

    /**
     * @param array<string, mixed> $params
     * @throws BaleApiException
     */
    public function call(string $method, array $params = []): mixed
    {
        if (!$this->isConfigured()) {
            throw new BaleApiException('BALE_BOT_TOKEN is not configured.');
        }
        try {
            $res = $this->http->request('POST', $this->apiBase . '/bot' . $this->token . '/' . $method, $params === [] ? '{}' : $params, $params === [] ? ['Content-Type' => 'application/json'] : []);
        } catch (\Throwable $e) {
            throw new BaleApiException('Bale API unreachable: ' . $this->mask($e->getMessage()));
        }
        $json = is_array($res['json']) ? $res['json'] : [];
        if (($json['ok'] ?? false) !== true) {
            throw new BaleApiException(
                $this->mask((string) ($json['description'] ?? ('HTTP ' . $res['status'] . ' ' . mb_substr($res['body'], 0, 200)))),
                (int) ($json['error_code'] ?? $res['status']),
                $json
            );
        }
        return $json['result'] ?? true;
    }

    public function tokenFingerprint(): string
    {
        return substr(hash('sha256', $this->token), 0, 16);
    }

    /** Never let the bot token reach logs or the screen. */
    private function mask(string $s): string
    {
        return $this->token === '' ? $s : str_replace($this->token, '<token>', $s);
    }

    /** @param array<string, mixed>|null $markup */
    public function sendMessage(int|string $chatId, string $text, ?array $markup = null): array
    {
        $p = ['chat_id' => $chatId, 'text' => $text];
        if ($markup !== null) {
            $p['reply_markup'] = $markup;
        }
        return (array) $this->call('sendMessage', $p);
    }

    /** @param array<string, mixed>|null $markup */
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $markup = null): void
    {
        $p = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        if ($markup !== null) {
            $p['reply_markup'] = $markup;
        }
        $this->call('editMessageText', $p);
    }

    public function answerCallbackQuery(string $id, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text]));
    }

    /** @param list<array{label: string, amount: int}> $prices amounts in Rials */
    public function sendInvoice(int|string $chatId, string $title, string $description, string $payload, string $providerToken, array $prices, ?string $photoUrl = null): array
    {
        return (array) $this->call('sendInvoice', array_filter([
            'chat_id' => $chatId,
            'title' => mb_substr($title, 0, 32),
            'description' => mb_substr($description, 0, 255),
            'payload' => $payload,
            'provider_token' => $providerToken,
            'prices' => $prices,
            'photo_url' => $photoUrl,
        ], fn ($v) => $v !== null));
    }

    public function answerPreCheckoutQuery(string $id, bool $ok, ?string $error = null): void
    {
        $this->call('answerPreCheckoutQuery', array_filter(['pre_checkout_query_id' => $id, 'ok' => $ok, 'error_message' => $ok ? null : $error], fn ($v) => $v !== null));
    }

    /** @return array{id?: string, status?: string, userID?: int, amount?: int} */
    public function inquireTransaction(string $transactionId): array
    {
        return (array) $this->call('inquireTransaction', ['transaction_id' => $transactionId]);
    }

    public function getMe(): array
    {
        return (array) $this->call('getMe');
    }

    public function setWebhook(string $url): void
    {
        $this->call('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return (array) $this->call('getWebhookInfo');
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        return (array) $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout]);
    }
}

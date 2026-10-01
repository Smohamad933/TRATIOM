<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Application\UseCases\Bale\BaleBotService;
use Terrarium\Infrastructure\Bale\BaleApiException;
use Terrarium\Infrastructure\Bale\BaleBotClient;
use Terrarium\Infrastructure\Bale\SafirClient;
use Terrarium\Infrastructure\Gateways\BaleGateway;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Repositories\BaleChatRepository;
use Terrarium\Kernel\Application;

final class BaleController
{
    public function __construct(private readonly Application $app) {}

    /** Bale → POST /api/v1/bale/webhook/{secret} */
    public function webhook(Request $r): Response
    {
        if (!hash_equals($this->app->baleWebhookSecret(), (string) ($r->params['secret'] ?? ''))) {
            throw HttpException::notFound();
        }
        if ($r->body !== []) {
            $this->app->get(BaleBotService::class)->handle($r->body);
        }
        return Response::json(['ok' => true]); // always 200 so Bale does not retry
    }

    /** Admin: bot status for the system page. */
    public function status(Request $r): Response
    {
        return Response::json(['success' => true, 'data' => $this->collectStatus()]);
    }

    /** Admin: register the webhook (after setting the bot token in .env). */
    public function setup(Request $r): Response
    {
        $bot = $this->app->get(BaleBotClient::class);
        if (!$bot->isConfigured()) {
            throw HttpException::badRequest('توکن ربات (BALE_BOT_TOKEN) در فایل .env تنظیم نشده است.');
        }
        $url = $this->app->baleWebhookUrl();
        if (!str_starts_with($url, 'https://')) {
            throw HttpException::badRequest('وب‌هوک بله فقط روی https کار می‌کند. APP_URL را https کنید و SSL را نصب کنید.');
        }
        try {
            $bot->setWebhook($url);
        } catch (BaleApiException $e) {
            throw HttpException::badRequest('خطای بله: ' . $e->getMessage());
        }
        return Response::json(['success' => true, 'data' => $this->collectStatus()]);
    }

    /** @return array<string, mixed> */
    private function collectStatus(): array
    {
        $c = $this->app->config;
        $bot = $this->app->get(BaleBotClient::class);
        $out = [
            'bot_configured' => $bot->isConfigured(),
            'bot' => null,
            'bot_error' => null,
            'webhook_url' => null,
            'webhook_expected' => preg_replace('#/webhook/.+$#', '/webhook/•••', $this->app->baleWebhookUrl()),
            'webhook_ok' => false,
            'wallet_configured' => (string) $c->get('bale.wallet_token', '') !== '',
            'wallet_test_mode' => str_starts_with((string) $c->get('bale.wallet_token', ''), 'WALLET-TEST'),
            'payment_enabled' => in_array('bale', $this->app->availableGateways(), true),
            'safir_configured' => $this->app->get(SafirClient::class)->isConfigured(),
            'otp_via_bale' => (bool) $c->get('bale.otp_enabled') && $this->app->get(SafirClient::class)->isConfigured(),
            'linked_users' => 0,
            'link' => null,
        ];
        try {
            $out['linked_users'] = $this->app->get(BaleChatRepository::class)->count();
        } catch (\Throwable) {
            // migration 003 not applied yet
        }
        if ($bot->isConfigured()) {
            try {
                $me = $bot->getMe();
                $out['bot'] = ['id' => $me['id'] ?? null, 'username' => $me['username'] ?? null, 'name' => $me['first_name'] ?? null];
                $out['link'] = $this->app->get(BaleGateway::class)->deepLink();
                $info = $bot->getWebhookInfo();
                $out['webhook_url'] = isset($info['url']) && $info['url'] !== '' ? preg_replace('#/webhook/.+$#', '/webhook/•••', (string) $info['url']) : '';
                $out['webhook_ok'] = ($info['url'] ?? '') === $this->app->baleWebhookUrl();
            } catch (BaleApiException $e) {
                $out['bot_error'] = $e->getMessage();
            }
        }
        return $out;
    }
}

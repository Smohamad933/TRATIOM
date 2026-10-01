<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Bale;

use Terrarium\Infrastructure\Http\HttpClient;

/**
 * Bale "Safir" messaging service — https://docs.bale.ai/safir
 * Delivers messages/OTP codes from your bot to a PHONE NUMBER, even if that user has never started the bot.
 * Requires an api-access-key from the Bale business panel (https://business.bale.ai). Each message costs credit.
 */
final class SafirClient
{
    private const URL = 'https://safir.bale.ai/api/v3/send_message';

    /** Safir error codes (docs.bale.ai/safir) */
    public const ERRORS = [
        2 => 'خطای داخلی سرور بله',
        3 => 'محدودیت تعداد ارسال',
        4 => 'ورودی نامعتبر',
        8 => 'شماره نامعتبر',
        17 => 'این شماره حساب بله ندارد',
        20 => 'اعتبار پنل کسب‌وکار بله کافی نیست',
        21 => 'به سقف تعداد مخاطبین بازو رسیده‌اید',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly int $botId,
        private readonly HttpClient $http = new HttpClient(15)
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->botId > 0;
    }

    /** 09121234567 → 989121234567 */
    public static function phone(string $mobile): string
    {
        $d = preg_replace('/\D+/', '', $mobile) ?? '';
        return '98' . substr($d, -10);
    }

    /** @return array{ok: bool, code: int, error: string} */
    public function sendOtp(string $mobile, string $code): array
    {
        return $this->send($mobile, ['otp_message' => ['otp' => $code]]);
    }

    /**
     * @param list<list<array{text: string, url?: string, copy_text?: string}>> $inlineKeyboard url/copy_text buttons only (Safir has no callbacks)
     * @return array{ok: bool, code: int, error: string}
     */
    public function sendText(string $mobile, string $text, array $inlineKeyboard = []): array
    {
        $message = ['text' => $text];
        if ($inlineKeyboard !== []) {
            $message['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }
        return $this->send($mobile, ['message' => $message]);
    }

    /** @return array{ok: bool, code: int, error: string} */
    private function send(string $mobile, array $messageData): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'code' => 0, 'error' => 'Safir is not configured (BALE_SAFIR_API_KEY / bot id).'];
        }
        try {
            $res = $this->http->request('POST', self::URL, [
                'request_id' => bin2hex(random_bytes(12)),
                'bot_id' => $this->botId,
                'phone_number' => self::phone($mobile),
                'message_data' => $messageData,
            ], ['api-access-key' => $this->apiKey]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 0, 'error' => 'Safir unreachable: ' . $e->getMessage()];
        }

        $json = is_array($res['json']) ? $res['json'] : [];
        $err = $json['error_data'][0] ?? null;
        if ($res['status'] >= 200 && $res['status'] < 300 && $err === null && !empty($json['message_id'])) {
            return ['ok' => true, 'code' => 0, 'error' => ''];
        }
        $code = (int) ($err['code'] ?? $json['code'] ?? $res['status']);
        $desc = (string) ($err['description'] ?? $json['message'] ?? $json['description'] ?? mb_substr($res['body'], 0, 200));
        return ['ok' => false, 'code' => $code, 'error' => (self::ERRORS[$code] ?? 'Safir error') . " ({$code}): {$desc}"];
    }
}

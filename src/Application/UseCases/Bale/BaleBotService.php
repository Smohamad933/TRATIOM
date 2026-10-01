<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Bale;

use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Application\UseCases\Payment\PaymentCallbackService;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Common\PaymentStatus;
use Terrarium\Infrastructure\Bale\BaleApiException;
use Terrarium\Infrastructure\Bale\BaleBotClient;
use Terrarium\Infrastructure\Gateways\BaleGateway;
use Terrarium\Infrastructure\Logging\Logger;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Repositories\BaleChatRepository;
use Terrarium\Infrastructure\Persistence\Repositories\BaleLoginRepository;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;
use Throwable;

/**
 * The Bale bot. Every interaction is an inline (glass) button; the only non-inline button is the
 * one-time "share my phone number" button, which Bale only offers as a keyboard button.
 */
final class BaleBotService
{
    private const STATUS = [
        'pending_payment' => '⏳ در انتظار پرداخت',
        'paid' => '✅ پرداخت‌شده',
        'processing' => '🛠 در حال آماده‌سازی',
        'shipped' => '🚚 ارسال‌شده',
        'completed' => '🎉 تحویل‌شده',
        'cancelled' => '❌ لغوشده',
        'draft' => 'پیش‌نویس',
    ];

    public function __construct(
        private readonly BaleBotClient $bot,
        private readonly BaleGateway $gateway,
        private readonly BaleChatRepository $chats,
        private readonly UserRepository $users,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly PaymentCallbackService $callback,
        private readonly Database $db,
        private readonly string $appUrl,
        private readonly Logger $logger,
        private readonly ?BaleLoginRepository $logins = null
    ) {}

    /** @param array<string, mixed> $u a Bale Update object */
    public function handle(array $u): void
    {
        try {
            if (isset($u['callback_query'])) {
                $this->onCallback($u['callback_query']);
            } elseif (isset($u['pre_checkout_query'])) {
                $this->onPreCheckout($u['pre_checkout_query']);
            } elseif (isset($u['message'])) {
                $this->onMessage($u['message']);
            }
        } catch (Throwable $e) {
            $this->logger->error('Bale update failed', ['update_id' => $u['update_id'] ?? null, 'exception' => $e]);
        }
    }

    // ------------------------------------------------------------------ messages

    private function onMessage(array $m): void
    {
        $chatId = (int) ($m['chat']['id'] ?? 0);
        $from = $m['from'] ?? [];
        if ($chatId === 0 || ($m['chat']['type'] ?? 'private') !== 'private') {
            return; // the bot only works in private chats
        }
        $chat = $this->chats->touch($chatId, (int) ($from['id'] ?? $chatId), $from['first_name'] ?? null);

        if (isset($m['successful_payment'])) {
            $this->onPaid($chatId, $m['successful_payment']);
            return;
        }
        if (isset($m['contact'])) {
            $this->onContact($chat, $m['contact'], (int) ($from['id'] ?? 0));
            return;
        }

        $text = trim((string) ($m['text'] ?? ''));
        $payload = '';
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?/u', $text, $mm)) {
            $payload = $mm[1] ?? '';
        }
        $this->route($chat, $payload);
    }

    /** Continue with a deep-link payload (or the menu) once the chat is linked to a verified mobile. */
    private function route(array $chat, string $payload): void
    {
        $chatId = (int) $chat['chat_id'];
        if (empty($chat['mobile'])) {
            $this->chats->setPending($chatId, $payload !== '' ? mb_substr($payload, 0, 100) : null);
            $this->askContact($chatId, (string) ($chat['first_name'] ?? ''));
            return;
        }
        if (str_starts_with($payload, 'login_')) {
            $this->askLoginConfirm($chat, substr($payload, 6));
            return;
        }
        if (str_starts_with($payload, 'pay_')) {
            $this->payByToken($chat, substr($payload, 4));
            return;
        }
        $this->showMenu($chat);
    }

    private function askContact(int $chatId, string $name): void
    {
        $hi = $name !== '' ? "سلام {$name} 👋" : 'سلام 👋';
        $this->bot->sendMessage($chatId, "{$hi}\nبه ربات فروشگاه تراریوم خوش آمدید 🌱\n\nبرای دیدن سفارش‌ها و پرداخت امن، یک بار شماره موبایلتان را با دکمه زیر تأیید کنید 👇", [
            'keyboard' => [[['text' => '📱 تأیید شماره موبایل', 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    private function onContact(array $chat, array $contact, int $fromId): void
    {
        $chatId = (int) $chat['chat_id'];
        // Only accept the user's OWN number (sharing someone else's contact must not link their orders)
        if ((int) ($contact['user_id'] ?? 0) !== $fromId || $fromId === 0) {
            $this->bot->sendMessage($chatId, '⚠️ لطفاً فقط با دکمه «تأیید شماره موبایل» شماره خودتان را ارسال کنید.');
            $this->askContact($chatId, '');
            return;
        }
        try {
            $mobile = OtpService::normalizeMobile((string) ($contact['phone_number'] ?? ''));
        } catch (Throwable) {
            $this->bot->sendMessage($chatId, '⚠️ فقط شماره‌های موبایل ایران پشتیبانی می‌شوند.', ['remove_keyboard' => true]);
            return;
        }
        $this->chats->setMobile($chatId, $mobile);
        $this->bot->sendMessage($chatId, "✅ شماره {$mobile} تأیید شد.", ['remove_keyboard' => true]);

        $pending = (string) ($chat['pending_payload'] ?? '');
        $this->chats->setPending($chatId, null);
        $chat['mobile'] = $mobile;
        $this->route($chat, $pending);
    }

    // ------------------------------------------------------------------ inline buttons

    private function onCallback(array $q): void
    {
        $chatId = (int) ($q['message']['chat']['id'] ?? $q['from']['id'] ?? 0);
        $messageId = isset($q['message']['message_id']) ? (int) $q['message']['message_id'] : null;
        $data = (string) ($q['data'] ?? '');
        $this->safe(fn () => $this->bot->answerCallbackQuery((string) $q['id']));

        $chat = $this->chats->touch($chatId, (int) ($q['from']['id'] ?? $chatId), $q['from']['first_name'] ?? null);
        if (empty($chat['mobile'])) {
            $this->route($chat, '');
            return;
        }

        match (true) {
            $data === 'menu' => $this->showMenu($chat, $messageId),
            $data === 'pending' => $this->showOrders($chat, $messageId, true),
            $data === 'orders' => $this->showOrders($chat, $messageId, false),
            str_starts_with($data, 'pay:') => $this->payOrder($chat, substr($data, 4)),
            str_starts_with($data, 'la:') => $this->decideLogin($chat, substr($data, 3), true, $messageId),
            str_starts_with($data, 'ld:') => $this->decideLogin($chat, substr($data, 3), false, $messageId),
            default => $this->showMenu($chat, $messageId),
        };
    }

    private function showMenu(array $chat, ?int $editMessageId = null): void
    {
        $user = $this->users->findByMobile((string) $chat['mobile']);
        $pending = $user ? count(array_filter($this->orders->listForUser((string) $user['id'], 20), fn ($o) => $o['status'] === OrderStatus::PENDING_PAYMENT->value)) : 0;
        $text = "🌱 فروشگاه تراریوم\nحساب: {$chat['mobile']}\n\n" . ($pending ? "شما {$pending} سفارش در انتظار پرداخت دارید." : 'از دکمه‌های زیر استفاده کنید.');
        $kb = [];
        if ($pending) {
            $kb[] = [['text' => "💳 پرداخت سفارش‌ها ({$pending})", 'callback_data' => 'pending']];
        }
        $kb[] = [['text' => '📦 سفارش‌های من', 'callback_data' => 'orders']];
        $kb[] = [['text' => '🛒 ساخت تراریوم در سایت', 'url' => $this->appUrl . '/']];
        $this->reply((int) $chat['chat_id'], $text, ['inline_keyboard' => $kb], $editMessageId);
    }

    private function showOrders(array $chat, ?int $editMessageId, bool $onlyPending): void
    {
        $user = $this->users->findByMobile((string) $chat['mobile']);
        $list = $user ? $this->orders->listForUser((string) $user['id'], 10) : [];
        if ($onlyPending) {
            $list = array_values(array_filter($list, fn ($o) => $o['status'] === OrderStatus::PENDING_PAYMENT->value));
        }
        $back = [['text' => '🔙 بازگشت', 'callback_data' => 'menu']];

        if ($list === []) {
            $msg = $onlyPending ? 'سفارش در انتظار پرداختی ندارید ✅' : "هنوز سفارشی با شماره {$chat['mobile']} ثبت نشده است.";
            $this->reply((int) $chat['chat_id'], $msg, ['inline_keyboard' => [[['text' => '🛒 ساخت تراریوم', 'url' => $this->appUrl . '/']], $back]], $editMessageId);
            return;
        }

        $kb = [];
        $lines = [];
        foreach (array_slice($list, 0, 8) as $o) {
            $amount = $this->toman((int) $o['total_price_cents']);
            $lines[] = "• {$o['order_number']} — {$amount}\n   " . (self::STATUS[$o['status']] ?? $o['status']);
            $kb[] = $o['status'] === OrderStatus::PENDING_PAYMENT->value
                ? [['text' => "💳 پرداخت {$o['order_number']} — {$amount}", 'callback_data' => 'pay:' . $o['id']]]
                : [['text' => "🔎 {$o['order_number']} — " . (self::STATUS[$o['status']] ?? ''), 'url' => $this->appUrl . '/#/orders/' . $o['id']]];
        }
        $kb[] = $back;
        $title = $onlyPending ? '💳 سفارش‌های در انتظار پرداخت:' : '📦 سفارش‌های شما:';
        $this->reply((int) $chat['chat_id'], $title . "\n\n" . implode("\n", $lines), ['inline_keyboard' => $kb], $editMessageId);
    }

    // ------------------------------------------------------------------ website login

    private function loginRow(array $chat, string $token, ?int $editId): ?array
    {
        $row = $this->logins !== null && preg_match('/^[a-f0-9]{32}$/', $token) ? $this->logins->find($token) : null;
        if ($row === null || $row['status'] !== 'pending' || BaleLoginRepository::expired($row)) {
            $this->reply((int) $chat['chat_id'], "⌛️ این درخواست ورود منقضی شده یا قبلاً استفاده شده است.\nدر سایت دوباره «ورود با بله» را بزنید.", ['inline_keyboard' => [[['text' => '🌐 رفتن به سایت', 'url' => $this->appUrl . '/']]]], $editId);
            return null;
        }
        if (!empty($row['mobile']) && $row['mobile'] !== $chat['mobile']) {
            $this->reply((int) $chat['chat_id'], "⚠️ این درخواست برای شماره {$row['mobile']} است، ولی حساب بله شما {$chat['mobile']} است.", null, $editId);
            return null;
        }
        return $row;
    }

    private function askLoginConfirm(array $chat, string $token): void
    {
        if ($this->loginRow($chat, $token, null) === null) {
            return;
        }
        $this->bot->sendMessage((int) $chat['chat_id'], "🔐 درخواست ورود به سایت تراریوم\nشماره: {$chat['mobile']}\n\nاگر خودتان در سایت «ورود با بله» را زده‌اید، تأیید کنید.", [
            'inline_keyboard' => [
                [['text' => '✅ تأیید ورود', 'callback_data' => 'la:' . $token]],
                [['text' => '❌ من نبودم', 'callback_data' => 'ld:' . $token]],
            ],
        ]);
    }

    private function decideLogin(array $chat, string $token, bool $approve, ?int $editId): void
    {
        if ($this->loginRow($chat, $token, $editId) === null) {
            return;
        }
        if (!$this->logins->decide($token, $approve, (string) $chat['mobile'], (int) $chat['chat_id'])) {
            $this->reply((int) $chat['chat_id'], '⌛️ این درخواست ورود دیگر معتبر نیست.', null, $editId);
            return;
        }
        $approve
            ? $this->reply((int) $chat['chat_id'], "✅ ورود تأیید شد.\nبه سایت برگردید؛ به‌صورت خودکار وارد می‌شوید.", ['inline_keyboard' => [[['text' => '🌐 بازگشت به سایت', 'url' => $this->appUrl . '/']], [['text' => '📋 منو', 'callback_data' => 'menu']]]], $editId)
            : $this->reply((int) $chat['chat_id'], '❌ درخواست ورود رد شد. کسی وارد حساب شما نشد.', ['inline_keyboard' => [[['text' => '📋 منو', 'callback_data' => 'menu']]]], $editId);
    }

    // ------------------------------------------------------------------ payment

    /** Deep link from the website / Safir message: the payment row already exists. */
    private function payByToken(array $chat, string $token): void
    {
        $chatId = (int) $chat['chat_id'];
        $payment = $this->payments->findByAuthority('bale', $token);
        $order = $payment ? $this->orders->findRow((string) $payment['order_id']) : null;
        if ($payment === null || $order === null) {
            $this->bot->sendMessage($chatId, '⚠️ این لینک پرداخت معتبر نیست یا منقضی شده است.', ['inline_keyboard' => [[['text' => '📋 منو', 'callback_data' => 'menu']]]]);
            return;
        }
        if (!$this->ownsOrder($chat, $order)) {
            $this->bot->sendMessage($chatId, "⚠️ این سفارش با شماره موبایل دیگری ثبت شده است.\nحساب بله شما: {$chat['mobile']}", ['inline_keyboard' => [[['text' => '📋 منو', 'callback_data' => 'menu']]]]);
            return;
        }
        if ($payment['status'] !== PaymentStatus::PENDING->value || $order['status'] !== OrderStatus::PENDING_PAYMENT->value) {
            // an older link: if the order still awaits payment issue a fresh invoice
            if ($order['status'] === OrderStatus::PENDING_PAYMENT->value) {
                $this->payOrder($chat, (string) $order['id']);
                return;
            }
            $this->bot->sendMessage($chatId, "سفارش {$order['order_number']} در وضعیت «" . (self::STATUS[$order['status']] ?? $order['status']) . '» است و نیازی به پرداخت ندارد.', [
                'inline_keyboard' => [[['text' => '🔎 مشاهده سفارش', 'url' => $this->appUrl . '/#/orders/' . $order['id']]], [['text' => '📋 منو', 'callback_data' => 'menu']]],
            ]);
            return;
        }
        $this->sendInvoice($chatId, $order, $token);
    }

    /** "Pay" button inside the bot: create a new Bale payment for the order. */
    private function payOrder(array $chat, string $orderId): void
    {
        $chatId = (int) $chat['chat_id'];
        $order = $this->orders->findRow($orderId);
        if ($order === null || !$this->ownsOrder($chat, $order)) {
            $this->bot->sendMessage($chatId, '⚠️ سفارش یافت نشد.');
            return;
        }
        if ($order['status'] !== OrderStatus::PENDING_PAYMENT->value) {
            $this->bot->sendMessage($chatId, "سفارش {$order['order_number']} در انتظار پرداخت نیست.", ['inline_keyboard' => [[['text' => '📋 منو', 'callback_data' => 'menu']]]]);
            return;
        }
        $token = BaleGateway::newToken();
        $paymentId = $this->payments->create($orderId, 'bale', (int) $order['total_price_cents'], (string) ($order['currency'] ?? 'IRR'));
        $this->payments->setAuthority($paymentId, $token);
        $this->db->query('UPDATE orders SET payment_gateway = :g, updated_at = :n WHERE id = :id', ['g' => 'bale', 'n' => Database::now(), 'id' => $orderId]);
        $this->sendInvoice($chatId, $order, $token);
    }

    private function sendInvoice(int $chatId, array $order, string $token): void
    {
        $amount = (int) $order['total_price_cents'];
        try {
            $this->bot->sendInvoice(
                $chatId,
                'سفارش ' . $order['order_number'],
                "تراریوم سفارشی 🌱\nگیرنده: {$order['recipient_name']}\nمبلغ: " . $this->toman($amount),
                $token,
                $this->gateway->walletToken(),
                [['label' => 'سفارش ' . $order['order_number'], 'amount' => $amount]]
            );
        } catch (BaleApiException $e) {
            $this->logger->error('Bale sendInvoice failed', ['order' => $order['order_number'], 'error' => $e->getMessage()]);
            $this->bot->sendMessage($chatId, '⚠️ ساخت درخواست پرداخت با خطا مواجه شد. لطفاً کمی بعد دوباره تلاش کنید.', ['inline_keyboard' => [[['text' => '🔄 تلاش دوباره', 'callback_data' => 'pay:' . $order['id']]]]]);
        }
    }

    /** Called by Bale right before the money moves. Must answer within 10 seconds. */
    private function onPreCheckout(array $q): void
    {
        $error = null;
        $payment = $this->payments->findByAuthority('bale', (string) ($q['invoice_payload'] ?? ''));
        $order = $payment ? $this->orders->findRow((string) $payment['order_id']) : null;
        $chat = $this->chats->find((int) ($q['from']['id'] ?? 0));

        if ($payment === null || $order === null) {
            $error = 'درخواست پرداخت نامعتبر است.';
        } elseif ($payment['status'] !== PaymentStatus::PENDING->value || $order['status'] !== OrderStatus::PENDING_PAYMENT->value) {
            $error = 'این سفارش دیگر در انتظار پرداخت نیست.';
        } elseif ((int) ($q['total_amount'] ?? -1) !== (int) $payment['amount_cents'] || (int) $payment['amount_cents'] !== (int) $order['total_price_cents']) {
            $error = 'مبلغ پرداخت با سفارش مطابقت ندارد.';
        } elseif ($chat === null || !$this->ownsOrder($chat, $order)) {
            $error = 'این سفارش متعلق به حساب شما نیست.';
        }

        $this->bot->answerPreCheckoutQuery((string) $q['id'], $error === null, $error);
        if ($error !== null) {
            $this->logger->warning('Bale pre-checkout rejected', ['payload' => $q['invoice_payload'] ?? null, 'reason' => $error]);
        }
    }

    private function onPaid(int $chatId, array $sp): void
    {
        $token = (string) ($sp['invoice_payload'] ?? '');
        $tx = (string) ($sp['telegram_payment_charge_id'] ?? '') ?: (string) ($sp['provider_payment_charge_id'] ?? '');
        $payment = $this->payments->findByAuthority('bale', $token);
        if ($payment === null || $tx === '') {
            $this->logger->error('Bale successful_payment for unknown payment', ['payload' => $token, 'tx' => $tx]);
            $this->bot->sendMessage($chatId, '⚠️ پرداخت دریافت شد ولی سفارش مربوطه پیدا نشد. لطفاً با پشتیبانی تماس بگیرید. شماره تراکنش: ' . $tx);
            return;
        }
        if (!$this->chats->claimTransaction($tx, (string) $payment['id'])) {
            $this->logger->error('Bale transaction replay blocked', ['tx' => $tx, 'payment' => $payment['id']]);
            return;
        }

        // Server-side verification (inquireTransaction: status=paid and exact amount) happens inside the gateway
        $this->gateway->rememberTransaction($token, $tx);
        $res = $this->callback->handle($this->gateway, ['token' => $token]);

        $order = $this->orders->findRow((string) $payment['order_id']);
        $view = [['text' => '🔎 مشاهده سفارش در سایت', 'url' => $this->appUrl . '/#/orders/' . $payment['order_id']]];
        if ($res['status'] === 'success') {
            $this->bot->sendMessage($chatId, "✅ پرداخت با موفقیت انجام شد.\n\nسفارش: {$res['order_number']}\nمبلغ: " . $this->toman((int) $payment['amount_cents']) . "\nکد پیگیری: {$res['reference_id']}\n\nسفارش شما به‌زودی آماده و ارسال می‌شود 🌱", [
                'inline_keyboard' => [$view, [['text' => '📋 منو', 'callback_data' => 'menu']]],
            ]);
        } else {
            $retry = $order && $order['status'] === OrderStatus::PENDING_PAYMENT->value ? [[['text' => '🔄 تلاش دوباره', 'callback_data' => 'pay:' . $order['id']]]] : [];
            $this->bot->sendMessage($chatId, "⚠️ {$res['message']}\nشماره تراکنش: {$tx}", ['inline_keyboard' => [...$retry, $view]]);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function ownsOrder(array $chat, array $order): bool
    {
        $user = $this->users->findById((string) $order['user_id']);
        return $user !== null && !empty($chat['mobile']) && hash_equals((string) $user['mobile'], (string) $chat['mobile']);
    }

    private function reply(int $chatId, string $text, ?array $markup, ?int $editMessageId): void
    {
        if ($editMessageId !== null) {
            try {
                $this->bot->editMessageText($chatId, $editMessageId, $text, $markup);
                return;
            } catch (BaleApiException) {
                // message too old / not modified → send a new one
            }
        }
        $this->bot->sendMessage($chatId, $text, $markup);
    }

    private function toman(int $rial): string
    {
        return number_format(intdiv($rial, 10)) . ' تومان';
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->logger->warning('Bale call failed', ['error' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Auth;

use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Infrastructure\Gateways\BaleGateway;
use Terrarium\Infrastructure\Persistence\Repositories\BaleLoginRepository;
use Terrarium\Infrastructure\Persistence\Repositories\TokenRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * Passwordless login with the Bale bot (replaces SMS codes):
 *   site → start() → link https://ble.ir/<bot>?start=login_<token>
 *   bot  → user shares their own phone once, then taps "✅ confirm login"
 *   site → poll() → session token for that verified mobile.
 */
final class BaleLoginService
{
    public const TTL = 300;

    /** @param list<string> $adminMobiles */
    public function __construct(
        private readonly BaleLoginRepository $logins,
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly BaleGateway $gateway,
        private readonly int $tokenTtlDays,
        private readonly array $adminMobiles
    ) {}

    /** @return array{token: string, link: string, expires_in: int} */
    public function start(?string $rawMobile, string $ip): array
    {
        $mobile = $rawMobile !== null && trim($rawMobile) !== '' ? OtpService::normalizeMobile($rawMobile) : null;
        if ($this->logins->recentFromIp($ip, 600) >= 20) {
            throw new ValidationException('درخواست‌های ورود بیش از حد است. چند دقیقه دیگر تلاش کنید.');
        }
        if ($this->gateway->botUsername() === '') {
            throw new \RuntimeException('ربات بله در دسترس نیست. تنظیمات BALE_BOT_TOKEN را بررسی کنید.');
        }
        $token = bin2hex(random_bytes(16));
        $this->logins->create($token, $mobile, $ip, self::TTL);
        if (random_int(1, 50) === 1) {
            $this->logins->purge();
        }
        return ['token' => $token, 'link' => $this->gateway->deepLink('login_' . $token), 'expires_in' => self::TTL];
    }

    /** @return array{status: string, token?: string, user?: array<string, mixed>} */
    public function poll(string $token): array
    {
        $row = preg_match('/^[a-f0-9]{32}$/', $token) ? $this->logins->find($token) : null;
        if ($row === null) {
            return ['status' => 'expired'];
        }
        if ($row['status'] === 'approved' && $this->logins->consume($token)) {
            $user = $this->users->findOrCreate((string) $row['mobile']);
            return [
                'status' => 'approved',
                'token' => $this->tokens->issue((string) $user['id'], $this->tokenTtlDays),
                'user' => OtpService::presentUser($user, $this->adminMobiles),
            ];
        }
        if ($row['status'] === 'pending') {
            return ['status' => BaleLoginRepository::expired($row) ? 'expired' : 'pending'];
        }
        return ['status' => $row['status'] === 'denied' ? 'denied' : 'expired'];
    }
}

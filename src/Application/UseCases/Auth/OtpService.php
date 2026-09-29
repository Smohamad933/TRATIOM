<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Auth;

use RuntimeException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Infrastructure\Persistence\Repositories\OtpRepository;
use Terrarium\Infrastructure\Persistence\Repositories\TokenRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;
use Terrarium\Infrastructure\SMS\SmsServiceInterface;

/**
 * Passwordless login with one-time SMS codes.
 */
final class OtpService
{
    /** @param array{expiry_minutes: int, length: int, max_attempts: int, resend_cooldown_seconds: int, max_per_hour_per_mobile: int, max_per_hour_per_ip: int} $options */
    public function __construct(
        private readonly SmsServiceInterface $sms,
        private readonly OtpRepository $otps,
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly array $options,
        private readonly int $tokenTtlDays = 30,
        /** @var list<string> */
        private readonly array $adminMobiles = []
    ) {}

    /**
     * Normalizes Iranian mobile numbers: 09xxxxxxxxx / +989xxxxxxxxx / 989xxxxxxxxx / 9xxxxxxxxx, Persian/Arabic digits.
     */
    public static function normalizeMobile(string $mobile): string
    {
        $mobile = strtr($mobile, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (!preg_match('/^9\d{9}$/', $digits)) {
            throw new ValidationException('شماره موبایل معتبر نیست. نمونه صحیح: 09121234567', ['field' => 'mobile']);
        }
        return '0' . $digits;
    }

    /**
     * @return array{expires_in: int, resend_in: int, code?: string}
     * @throws TooManyRequestsException
     */
    public function requestCode(string $rawMobile, string $ip, bool $exposeCode = false): array
    {
        $mobile = self::normalizeMobile($rawMobile);
        $o = $this->options;

        $latest = $this->otps->latest($mobile);
        if ($latest) {
            $elapsed = time() - strtotime($latest['created_at'] . ' UTC');
            if ($elapsed < $o['resend_cooldown_seconds']) {
                $wait = $o['resend_cooldown_seconds'] - $elapsed;
                throw new TooManyRequestsException("لطفاً {$wait} ثانیه دیگر دوباره تلاش کنید.", $wait);
            }
        }
        $hourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
        if ($this->otps->countForMobileSince($mobile, $hourAgo) >= $o['max_per_hour_per_mobile']) {
            throw new TooManyRequestsException('تعداد درخواست کد برای این شماره بیش از حد مجاز است. یک ساعت دیگر تلاش کنید.', 3600);
        }
        if ($ip !== '' && $this->otps->countForIpSince($ip, $hourAgo) >= $o['max_per_hour_per_ip']) {
            throw new TooManyRequestsException('تعداد درخواست‌ها بیش از حد مجاز است. بعداً تلاش کنید.', 3600);
        }

        $length = max(4, min(8, $o['length']));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $o['expiry_minutes'] * 60);

        $this->otps->create($mobile, password_hash($code, PASSWORD_DEFAULT), $expiresAt, $ip);

        if (!$this->sms->sendOtp($mobile, $code)) {
            throw new RuntimeException('ارسال پیامک با خطا مواجه شد. لطفاً چند دقیقه دیگر تلاش کنید.');
        }

        $out = ['expires_in' => $o['expiry_minutes'] * 60, 'resend_in' => $o['resend_cooldown_seconds']];
        if ($exposeCode) {
            $out['code'] = $code; // development only
        }
        return $out;
    }

    /**
     * @return array{token: string, user: array<string, mixed>}
     */
    public function verifyCode(string $rawMobile, string $code): array
    {
        $mobile = self::normalizeMobile($rawMobile);
        $code = preg_replace('/\D+/', '', strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])) ?? '';

        $otp = $this->otps->latestUnused($mobile);
        if ($otp === null || strtotime($otp['expires_at'] . ' UTC') < time()) {
            throw new ValidationException('کد منقضی شده است. لطفاً کد جدید درخواست کنید.', ['field' => 'code']);
        }
        if ((int) $otp['attempts'] >= $this->options['max_attempts']) {
            $this->otps->markUsed((int) $otp['id']);
            throw new ValidationException('تعداد تلاش‌های ناموفق بیش از حد مجاز است. کد جدید درخواست کنید.', ['field' => 'code']);
        }

        $this->otps->incrementAttempts((int) $otp['id']);
        if ($code === '' || !password_verify($code, (string) $otp['code_hash'])) {
            throw new ValidationException('کد واردشده صحیح نیست.', ['field' => 'code']);
        }
        if (!$this->otps->markUsed((int) $otp['id'])) {
            throw new ValidationException('این کد قبلاً استفاده شده است.', ['field' => 'code']);
        }

        $user = $this->users->findOrCreate($mobile);
        $token = $this->tokens->issue((string) $user['id'], $this->tokenTtlDays);

        return ['token' => $token, 'user' => self::presentUser($user, $this->adminMobiles)];
    }

    /** @param list<string> $adminMobiles */
    public static function presentUser(array $user, array $adminMobiles): array
    {
        return [
            'id' => $user['id'],
            'mobile' => $user['mobile'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'is_admin' => self::isAdmin($user, $adminMobiles),
        ];
    }

    /** @param list<string> $adminMobiles */
    public static function isAdmin(array $user, array $adminMobiles): bool
    {
        if (!empty($user['is_admin'])) {
            return true;
        }
        foreach ($adminMobiles as $m) {
            try {
                if (self::normalizeMobile($m) === $user['mobile']) {
                    return true;
                }
            } catch (ValidationException) {
                // ignore malformed entries in ADMIN_MOBILES
            }
        }
        return false;
    }
}

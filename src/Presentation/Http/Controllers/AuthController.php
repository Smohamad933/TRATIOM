<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Application\UseCases\Auth\BaleLoginService;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Repositories\TokenRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;
use Terrarium\Kernel\Application;

final class AuthController
{
    public function __construct(private readonly Application $app) {}

    public function methods(Request $r): Response
    {
        return Response::json(['success' => true, 'data' => ['method' => $this->app->authMethod()]]);
    }

    public function baleStart(Request $r): Response
    {
        $this->requireBale();
        $mobile = $r->input('mobile');
        return Response::json(['success' => true, 'data' => $this->app->get(BaleLoginService::class)->start(is_string($mobile) ? $mobile : null, $r->ip)]);
    }

    public function balePoll(Request $r): Response
    {
        $this->requireBale();
        return Response::json(['success' => true, 'data' => $this->app->get(BaleLoginService::class)->poll((string) $r->input('token', ''))]);
    }

    private function requireBale(): void
    {
        if ($this->app->authMethod() !== 'bale') {
            throw HttpException::notFound('ورود با بله فعال نیست.');
        }
    }

    public function requestOtp(Request $r): Response
    {
        if ($this->app->authMethod() === 'bale') {
            throw HttpException::badRequest('ورود فقط از طریق ربات بله انجام می‌شود. صفحه را تازه کنید (Ctrl+F5).');
        }
        $mobile = $r->requireString('mobile', 'شماره موبایل', 20);
        // Expose the code in the response only for local development with the log SMS driver
        $expose = !$this->app->isProduction() && $this->app->config->get('sms.default') === 'log';
        $res = $this->app->get(OtpService::class)->requestCode($mobile, $r->ip, $expose);
        return Response::json([
            'success' => true,
            'message' => 'کد تأیید ارسال شد.',
            'data' => $res + ['mobile' => OtpService::normalizeMobile($mobile)],
        ]);
    }

    public function verifyOtp(Request $r): Response
    {
        if ($this->app->authMethod() === 'bale') {
            throw HttpException::badRequest('ورود فقط از طریق ربات بله انجام می‌شود.');
        }
        $res = $this->app->get(OtpService::class)->verifyCode(
            $r->requireString('mobile', 'شماره موبایل', 20),
            $r->requireString('code', 'کد تأیید', 10)
        );
        return Response::json(['success' => true, 'data' => $res]);
    }

    public function me(Request $r): Response
    {
        return Response::json(['success' => true, 'data' => OtpService::presentUser($r->attributes['user'], (array) $this->app->config->get('app.admin_mobiles', []))]);
    }

    public function updateProfile(Request $r): Response
    {
        $first = trim((string) $r->input('first_name', ''));
        $last = trim((string) $r->input('last_name', ''));
        if (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            return Response::json(['success' => false, 'error' => ['code' => 'validation_failed', 'message' => 'نام بیش از حد طولانی است.']], 422);
        }
        $users = $this->app->get(UserRepository::class);
        $users->updateProfile((string) $r->attributes['user']['id'], $first ?: null, $last ?: null);
        $user = (array) $users->findById((string) $r->attributes['user']['id']);
        return Response::json(['success' => true, 'data' => OtpService::presentUser($user, (array) $this->app->config->get('app.admin_mobiles', []))]);
    }

    public function logout(Request $r): Response
    {
        $this->app->get(TokenRepository::class)->revoke((string) $r->attributes['token']);
        return Response::json(['success' => true]);
    }
}

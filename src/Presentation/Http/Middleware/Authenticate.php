<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Middleware;

use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Repositories\TokenRepository;
use Terrarium\Kernel\Application;

final class Authenticate
{
    public function __construct(private readonly Application $app, private readonly bool $requireAdmin = false) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized();
        }
        $user = $this->app->get(TokenRepository::class)->userForToken($token);
        if ($user === null) {
            throw HttpException::unauthorized('نشست شما منقضی شده است. دوباره وارد شوید.');
        }
        $user['is_admin'] = OtpService::isAdmin($user, (array) $this->app->config->get('app.admin_mobiles', []));
        if ($this->requireAdmin && !$user['is_admin']) {
            throw HttpException::forbidden();
        }
        $request->attributes['user'] = $user;
        $request->attributes['token'] = $token;
        return $next($request);
    }
}

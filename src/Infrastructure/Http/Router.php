<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Http;

use Closure;

/**
 * Tiny router: exact segments and {param} placeholders, plus optional middleware per route.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable, middleware: list<callable>}> */
    private array $routes = [];

    /** @param list<callable> $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler, 'middleware' => $middleware];
    }

    public function get(string $p, callable $h, array $m = []): void { $this->add('GET', $p, $h, $m); }
    public function post(string $p, callable $h, array $m = []): void { $this->add('POST', $p, $h, $m); }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $method = $request->method === 'HEAD' ? 'GET' : $request->method;
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            $handler = Closure::fromCallable($route['handler']);
            foreach (array_reverse($route['middleware']) as $mw) {
                $next = $handler;
                $handler = fn (Request $r): Response => $mw($r, $next);
            }
            return $handler($request);
        }

        if ($allowed) {
            throw new HttpException(405, 'متد درخواست مجاز نیست.', ['allowed' => array_values(array_unique($allowed))], 'method_not_allowed');
        }
        throw HttpException::notFound('مسیر درخواستی یافت نشد.');
    }
}

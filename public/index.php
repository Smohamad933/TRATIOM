<?php

declare(strict_types=1);

/**
 * Terrarium Configurator — front controller (IIS FastCGI / php -S).
 */

use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;

$path = parse_url((string) ($_SERVER['HTTP_X_ORIGINAL_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// Not installed yet → web installer (public/install.php)
if (!is_file(dirname(__DIR__) . '/storage/installed.lock') && ($path === '/' || $path === '/index.html' || str_starts_with($path, '/admin'))) {
    header('Location: /install.php', true, 302);
    return;
}

// Storefront & admin SPA entry points (IIS serves these directly as default documents; this covers php -S)
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}
if ($path === '/admin' || $path === '/admin/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/admin/index.html');
    return;
}

/** @var \Terrarium\Kernel\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = (require dirname(__DIR__) . '/bootstrap/routes.php')($app);

try {
    $request = Request::fromGlobals();
} catch (HttpException $e) {
    Response::json(['success' => false, 'error' => ['code' => 'bad_request', 'message' => $e->getMessage()]], 400)->send();
    return;
}

if ($request->method === 'OPTIONS') {
    $app->handle($request, new \Terrarium\Infrastructure\Http\Router())->send(); // CORS preflight headers only
    return;
}

$app->handle($request, $router)->send();

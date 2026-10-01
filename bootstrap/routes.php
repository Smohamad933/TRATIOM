<?php

declare(strict_types=1);

use Terrarium\Infrastructure\Http\Router;
use Terrarium\Kernel\Application;
use Terrarium\Presentation\Http\Controllers\AdminController;
use Terrarium\Presentation\Http\Controllers\AuthController;
use Terrarium\Presentation\Http\Controllers\BaleController;
use Terrarium\Presentation\Http\Controllers\CatalogController;
use Terrarium\Presentation\Http\Controllers\OrderController;
use Terrarium\Presentation\Http\Controllers\PaymentController;
use Terrarium\Presentation\Http\Controllers\SystemController;
use Terrarium\Presentation\Http\Middleware\Authenticate;

/** @var Application $app */
return static function (Application $app): Router {
    $r = new Router();
    $auth = [new Authenticate($app)];
    $admin = [new Authenticate($app, requireAdmin: true)];

    $system = new SystemController($app);
    $catalog = new CatalogController($app);
    $authC = new AuthController($app);
    $orders = new OrderController($app);
    $payments = new PaymentController($app);
    $adm = new AdminController($app);
    $bale = new BaleController($app);

    // System
    $r->get('/health', [$system, 'health']);
    $r->get('/api/v1', [$system, 'info']);

    // Public catalog & configurator
    $r->get('/api/v1/catalog', [$catalog, 'index']);
    $r->post('/api/v1/configurator/validate', [$catalog, 'validate']);

    // Auth (OTP)
    $r->get('/api/v1/auth/methods', [$authC, 'methods']);
    $r->post('/api/v1/auth/bale/start', [$authC, 'baleStart']);
    $r->post('/api/v1/auth/bale/poll', [$authC, 'balePoll']);
    $r->post('/api/v1/auth/otp/request', [$authC, 'requestOtp']);
    $r->post('/api/v1/auth/otp/verify', [$authC, 'verifyOtp']);
    $r->get('/api/v1/auth/me', [$authC, 'me'], $auth);
    $r->post('/api/v1/auth/profile', [$authC, 'updateProfile'], $auth);
    $r->post('/api/v1/auth/logout', [$authC, 'logout'], $auth);

    // Orders
    $r->post('/api/v1/orders', [$orders, 'store'], $auth);
    $r->get('/api/v1/orders', [$orders, 'index'], $auth);
    $r->get('/api/v1/orders/{id}', [$orders, 'show'], $auth);
    $r->post('/api/v1/orders/{id}/pay', [$orders, 'pay'], $auth);

    // Payment gateway callbacks (GET for ZarinPal/Zibal/Stripe, POST for IDPay)
    $r->get('/api/v1/payments/verify/{gateway}', [$payments, 'callback']);
    $r->post('/api/v1/payments/verify/{gateway}', [$payments, 'callback']);
    $r->get('/api/v1/payments/test-gateway', [$payments, 'testGateway']);

    // Bale bot webhook (secret path; Bale sends no signature)
    $r->post('/api/v1/bale/webhook/{secret}', [$bale, 'webhook']);

    // Admin (only GET/POST are used so no extra IIS verb configuration is needed)
    $r->get('/api/v1/admin/dashboard', [$adm, 'dashboard'], $admin);
    $r->get('/api/v1/admin/system', [$adm, 'system'], $admin);
    $r->get('/api/v1/admin/catalog', [$adm, 'catalog'], $admin);
    $r->post('/api/v1/admin/catalog/{type}', [$adm, 'createItem'], $admin);
    $r->post('/api/v1/admin/catalog/{type}/{id}', [$adm, 'updateItem'], $admin);
    $r->get('/api/v1/admin/rules', [$adm, 'rules'], $admin);
    $r->post('/api/v1/admin/rules', [$adm, 'createRule'], $admin);
    $r->post('/api/v1/admin/rules/{id}/toggle', [$adm, 'toggleRule'], $admin);
    $r->post('/api/v1/admin/rules/{id}/delete', [$adm, 'deleteRule'], $admin);
    $r->get('/api/v1/admin/orders', [$adm, 'orders'], $admin);
    $r->get('/api/v1/admin/orders/{id}', [$adm, 'order'], $admin);
    $r->post('/api/v1/admin/orders/{id}/status', [$adm, 'changeStatus'], $admin);

    $r->get('/api/v1/admin/bale', [$bale, 'status'], $admin);
    $r->post('/api/v1/admin/bale/setup', [$bale, 'setup'], $admin);

    return $r;
};

<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use InvalidArgumentException;
use Terrarium\Application\UseCases\Payment\PaymentCallbackService;
use Terrarium\Infrastructure\Gateways\TestGateway;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Kernel\Application;

final class PaymentController
{
    public function __construct(private readonly Application $app) {}

    /** Customer returns here from the gateway (GET or POST, depending on the provider). */
    public function callback(Request $r): Response
    {
        try {
            $gateway = $this->app->gatewayInstance($r->params['gateway']);
        } catch (InvalidArgumentException) {
            throw HttpException::notFound('درگاه نامعتبر است.');
        }
        $params = array_merge($r->query, $r->body);
        $res = $this->app->get(PaymentCallbackService::class)->handle($gateway, $params);

        $query = http_build_query(array_filter([
            'payment' => $res['status'],
            'order' => $res['order_number'],
            'ref' => $res['reference_id'],
            'msg' => $res['message'],
        ]));
        return Response::redirect($this->app->config->get('app.url') . '/?' . $query . '#result');
    }

    /** Local payment simulator (only available outside production). */
    public function testGateway(Request $r): Response
    {
        if ($this->app->isProduction()) {
            throw HttpException::notFound();
        }
        $gw = $this->app->gatewayInstance('test');
        assert($gw instanceof TestGateway);
        $token = (string) $r->input('token', '');
        $amount = (int) $r->input('amount', 0);
        if (!hash_equals($gw->sign($token, $amount), (string) $r->input('sig', ''))) {
            throw HttpException::badRequest('امضای نامعتبر');
        }
        $cb = (string) $r->input('callback', '');
        $base = $this->app->config->get('app.url') . '/api/v1/payments/verify/test';
        if (!str_starts_with($cb, $base)) {
            $cb = $base;
        }
        $link = fn (string $result) => htmlspecialchars($cb . '?' . http_build_query(['token' => $token, 'amount' => $amount, 'sig' => $r->input('sig'), 'result' => $result]), ENT_QUOTES);
        $order = htmlspecialchars((string) $r->input('order', ''), ENT_QUOTES);
        $amountFmt = number_format($amount);

        return Response::html(<<<HTML
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>درگاه آزمایشی</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body class="center-page"><div class="card narrow">
<div class="badge warn">محیط آزمایشی — پولی کسر نمی‌شود</div>
<h1>درگاه پرداخت شبیه‌سازی‌شده</h1>
<p>سفارش: <b>{$order}</b></p><p>مبلغ: <b>{$amountFmt} ریال</b></p>
<div class="row gap"><a class="btn primary" href="{$link('success')}">پرداخت موفق</a>
<a class="btn danger" href="{$link('failed')}">انصراف / ناموفق</a></div>
</div></body></html>
HTML);
    }
}

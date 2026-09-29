<?php

declare(strict_types=1);

namespace Terrarium\Presentation\Http\Controllers;

use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Application\UseCases\Ordering\PlaceOrderService;
use Terrarium\Infrastructure\Http\HttpException;
use Terrarium\Infrastructure\Http\Request;
use Terrarium\Infrastructure\Http\Response;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;
use Terrarium\Kernel\Application;

final class OrderController
{
    public function __construct(private readonly Application $app) {}

    public function store(Request $r): Response
    {
        $user = $r->attributes['user'];
        $config = $r->input('configuration');
        if (!is_array($config)) {
            throw new ValidationException('اطلاعات ترکیب تراریوم ارسال نشده است.', ['field' => 'configuration']);
        }

        $phone = $r->requireString('recipient_phone', 'شماره تماس گیرنده', 20);
        try {
            $phone = OtpService::normalizeMobile($phone);
        } catch (ValidationException) {
            if (!preg_match('/^0\d{9,11}$/', preg_replace('/\D/', '', $phone) ?? '')) {
                throw new ValidationException('شماره تماس گیرنده معتبر نیست.', ['field' => 'recipient_phone']);
            }
        }
        $postal = trim((string) $r->input('postal_code', ''));
        if ($postal !== '' && !preg_match('/^\d{10}$/', $postal)) {
            throw new ValidationException('کد پستی باید ۱۰ رقم باشد.', ['field' => 'postal_code']);
        }
        $note = trim((string) $r->input('customer_note', ''));
        if (mb_strlen($note) > 1000) {
            throw new ValidationException('توضیحات بیش از حد طولانی است.', ['field' => 'customer_note']);
        }

        $shipping = [
            'recipient_name' => $r->requireString('recipient_name', 'نام گیرنده', 150),
            'recipient_phone' => $phone,
            'shipping_address' => $r->requireString('shipping_address', 'آدرس', 1000),
            'postal_code' => $postal ?: null,
            'customer_note' => $note ?: null,
        ];

        $gateway = (string) $r->input('gateway', $this->app->availableGateways()[0] ?? '');
        $res = $this->app->get(PlaceOrderService::class)->place($user, ValidateConfigurationInput::fromArray($config), $shipping, $gateway);
        return Response::json(['success' => true, 'data' => $res], 201);
    }

    public function index(Request $r): Response
    {
        $orders = $this->app->get(OrderRepository::class)->listForUser((string) $r->attributes['user']['id']);
        return Response::json(['success' => true, 'data' => array_map([$this, 'publicOrder'], $orders)]);
    }

    public function show(Request $r): Response
    {
        $repo = $this->app->get(OrderRepository::class);
        $row = $repo->findRow($r->params['id']);
        if ($row === null || $row['user_id'] !== $r->attributes['user']['id']) {
            throw HttpException::notFound('سفارش یافت نشد.');
        }
        $order = $this->publicOrder($repo->present($row));
        $order['items'] = array_map(fn ($i) => ['snapshot' => $i['snapshot_data'], 'quantity' => $i['quantity'], 'unit_price_cents' => $i['unit_price_cents']], $repo->itemsFor($row['id']));
        $order['payments'] = array_map(fn ($p) => array_intersect_key($p, array_flip(['gateway', 'status', 'amount_cents', 'reference_id', 'created_at', 'verified_at'])), $this->app->get(PaymentRepository::class)->forOrder($row['id']));
        return Response::json(['success' => true, 'data' => $order]);
    }

    public function pay(Request $r): Response
    {
        $gateway = $r->input('gateway');
        $res = $this->app->get(PlaceOrderService::class)->retryPayment($r->attributes['user'], $r->params['id'], is_string($gateway) && $gateway !== '' ? $gateway : null);
        return Response::json(['success' => true, 'data' => $res]);
    }

    private function publicOrder(array $o): array
    {
        return array_intersect_key($o, array_flip([
            'id', 'order_number', 'status', 'total_price_cents', 'shipping_cents', 'discount_cents', 'currency',
            'payment_gateway', 'recipient_name', 'recipient_phone', 'shipping_address', 'postal_code', 'customer_note', 'paid_at', 'created_at',
        ]));
    }
}

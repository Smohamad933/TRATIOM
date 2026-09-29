<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Ordering;

use Terrarium\Application\DTO\CreateOrderInput;
use Terrarium\Application\DTO\ValidateConfigurationInput;
use Terrarium\Application\Exceptions\NotFoundException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Application\UseCases\Configurator\ValidateConfigurationUseCase;
use Terrarium\Application\UseCases\Payment\InitiatePaymentUseCase;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Common\PaymentStatus;
use Terrarium\Domain\Ordering\Order;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Logging\Logger;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;

final class PlaceOrderService
{
    /** @param callable(string): PaymentGatewayInterface $gatewayResolver */
    public function __construct(
        private readonly Database $db,
        private readonly ValidateConfigurationUseCase $validator,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly mixed $gatewayResolver,
        private readonly string $appUrl,
        private readonly Logger $logger,
        private readonly CreateOrderUseCase $createOrder = new CreateOrderUseCase(),
        private readonly InitiatePaymentUseCase $initiate = new InitiatePaymentUseCase()
    ) {}

    /**
     * @param array<string, mixed> $user
     * @param array{recipient_name: string, recipient_phone: string, shipping_address: string, postal_code: ?string, customer_note: ?string} $shipping
     * @return array{order_id: string, order_number: string, total_cents: int, payment_url: string}
     */
    public function place(array $user, ValidateConfigurationInput $input, array $shipping, string $gatewayName): array
    {
        $gateway = ($this->gatewayResolver)($gatewayName);

        $out = $this->validator->execute($input, (string) $user['id']);
        if (!$out['is_valid']) {
            throw new ValidationException('ترکیب انتخابی قابل سفارش نیست.', [
                'violations' => ValidateConfigurationUseCase::present($out)['violations'],
            ]);
        }
        $configuration = $out['configuration'];
        $snapshot = $configuration->createSnapshot();
        $snapshot['price_breakdown'] = $out['breakdown']->toArray();

        $order = $this->createOrder->execute(
            new CreateOrderInput(userId: (string) $user['id'], configurationId: $configuration->id, paymentGateway: $gatewayName),
            $configuration,
            $out['breakdown']->shipping,
            $snapshot
        );

        $this->db->transaction(function () use ($configuration, $snapshot, $order, $shipping, $gatewayName) {
            $this->orders->saveConfiguration($configuration, $snapshot);
            $this->orders->create($order, $shipping, $gatewayName);
        });

        $paymentUrl = $this->startPayment($order, $gateway, (string) $user['mobile']);

        return [
            'order_id' => $order->id,
            'order_number' => $order->orderNumber,
            'total_cents' => $order->totalPrice->amount,
            'payment_url' => $paymentUrl,
        ];
    }

    /** Retry payment for an order still awaiting payment. */
    public function retryPayment(array $user, string $orderId, ?string $gatewayName = null): array
    {
        $row = $this->orders->findRow($orderId);
        if ($row === null || $row['user_id'] !== $user['id']) {
            throw new NotFoundException('سفارش یافت نشد.');
        }
        if ($row['status'] !== OrderStatus::PENDING_PAYMENT->value) {
            throw new ValidationException('این سفارش در وضعیت انتظار پرداخت نیست.');
        }
        $gatewayName ??= (string) $row['payment_gateway'];
        $gateway = ($this->gatewayResolver)($gatewayName);
        if ($gatewayName !== $row['payment_gateway']) {
            $this->db->query('UPDATE orders SET payment_gateway = :g, updated_at = :now WHERE id = :id', [
                'g' => $gatewayName, 'now' => Database::now(), 'id' => $orderId,
            ]);
        }
        $order = $this->orders->toDomain($row);
        return [
            'order_id' => $order->id,
            'order_number' => $order->orderNumber,
            'total_cents' => $order->totalPrice->amount,
            'payment_url' => $this->startPayment($order, $gateway, (string) $user['mobile']),
        ];
    }

    private function startPayment(Order $order, PaymentGatewayInterface $gateway, string $mobile): string
    {
        $name = $gateway->getGatewayIdentifier();
        $paymentId = $this->payments->create($order->id, $name, $order->totalPrice->amount, $order->totalPrice->currency);
        $callbackUrl = $this->appUrl . '/api/v1/payments/verify/' . $name;

        try {
            $res = $this->initiate->execute($order, $gateway, $callbackUrl, ['mobile' => $mobile]);
        } catch (PaymentGatewayException $e) {
            $this->payments->markResult($paymentId, PaymentStatus::FAILED, null, ['error' => $e->getMessage(), 'provider' => $e->providerResponse]);
            $this->logger->error('Payment initiation failed', ['gateway' => $name, 'order' => $order->orderNumber, 'exception' => $e]);
            throw new PaymentUnavailableException('اتصال به درگاه پرداخت برقرار نشد. سفارش شما ثبت شده و می‌توانید از بخش «سفارش‌های من» دوباره پرداخت کنید.', $order->id);
        }

        $this->payments->setAuthority($paymentId, $res['authority_or_token']);
        return $res['payment_url'];
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Payment;

use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Common\PaymentStatus;
use Terrarium\Domain\Payment\PaymentGatewayException;
use Terrarium\Domain\Payment\PaymentGatewayInterface;
use Terrarium\Infrastructure\Logging\Logger;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Repositories\CatalogRepository;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;

/**
 * Handles the customer's return from the payment gateway.
 * Idempotent: repeated callbacks for the same authority never double-process an order.
 */
final class PaymentCallbackService
{
    public function __construct(
        private readonly Database $db,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly CatalogRepository $catalog,
        private readonly Logger $logger,
        private readonly VerifyPaymentUseCase $verify = new VerifyPaymentUseCase()
    ) {}

    /**
     * @param array<string, mixed> $params merged query + POST parameters from the gateway
     * @return array{status: 'success'|'failed'|'error', order_number: ?string, reference_id: ?string, message: string}
     */
    public function handle(PaymentGatewayInterface $gateway, array $params): array
    {
        $parsed = $gateway->parseCallback($params);
        $token = $parsed['token'];
        if ($token === null || $token === '') {
            return $this->result('error', null, null, 'اطلاعات بازگشتی از درگاه ناقص است.');
        }

        return $this->db->transaction(function () use ($gateway, $parsed, $token) {
            $payment = $this->payments->findByAuthority($gateway->getGatewayIdentifier(), $token, true);
            if ($payment === null) {
                $this->logger->warning('Payment callback for unknown authority', ['gateway' => $gateway->getGatewayIdentifier(), 'token' => $token]);
                return $this->result('error', null, null, 'تراکنش یافت نشد.');
            }
            $orderRow = $this->orders->findRow((string) $payment['order_id'], true);
            if ($orderRow === null) {
                return $this->result('error', null, null, 'سفارش یافت نشد.');
            }
            $number = (string) $orderRow['order_number'];

            // Idempotency: this payment has already been processed
            if ($payment['status'] === PaymentStatus::SUCCESS->value) {
                return $this->result('success', $number, $payment['reference_id'], 'پرداخت قبلاً با موفقیت ثبت شده است.');
            }

            // IMPORTANT: callback parameters are attacker-controllable. A "failed" callback must never
            // permanently fail the payment, otherwise a forged request could block a genuine payment.
            // Only server-to-server verification is trusted, and the payment stays pending on failure.
            if (!$parsed['provider_reports_success']) {
                return $this->result('failed', $number, null, 'پرداخت لغو شد یا ناموفق بود. می‌توانید دوباره تلاش کنید.');
            }

            $orderIsPending = $orderRow['status'] === OrderStatus::PENDING_PAYMENT->value;
            $order = $this->orders->toDomain($orderRow);
            if ((int) $payment['amount_cents'] !== $order->totalPrice->amount) {
                $this->logger->error('Payment amount mismatch', ['order' => $number]);
                return $this->result('error', $number, null, 'مغایرت مبلغ تراکنش.');
            }

            try {
                $res = $orderIsPending
                    ? $this->verify->execute($order, $gateway, $token) // also transitions the order to PAID
                    : $gateway->verifyPayment($token, $order->totalPrice, $order->id);
            } catch (PaymentGatewayException $e) {
                // leave the payment pending so a later callback/retry can verify it
                $this->logger->error('Payment verification error', ['order' => $number, 'exception' => $e]);
                return $this->result('error', $number, null, 'خطا در ارتباط با درگاه برای تأیید پرداخت. اگر وجه کسر شده، با پشتیبانی تماس بگیرید.');
            }

            if (!$res['success']) {
                // keep pending: the provider may still confirm it on a later (genuine) callback
                $this->logger->info('Payment not confirmed by provider', ['order' => $number, 'response' => $res['raw_response']]);
                return $this->result('failed', $number, null, 'پرداخت توسط درگاه تأیید نشد.');
            }

            if (!$orderIsPending) {
                // Money was captured but the order was cancelled meanwhile -> record it and flag for refund
                $res['raw_response']['_refund_required'] = true;
                $this->payments->markResult((string) $payment['id'], PaymentStatus::SUCCESS, $res['reference_id'], $res['raw_response']);
                $this->logger->error('REFUND REQUIRED: payment captured for a non-pending order', ['order' => $number, 'status' => $orderRow['status'], 'ref' => $res['reference_id']]);
                return $this->result('failed', $number, $res['reference_id'], 'این سفارش دیگر در انتظار پرداخت نبود. مبلغ پرداختی به حساب شما بازگردانده می‌شود؛ لطفاً با پشتیبانی تماس بگیرید.');
            }

            $this->payments->markResult((string) $payment['id'], PaymentStatus::SUCCESS, $res['reference_id'], $res['raw_response']);
            $this->orders->updateStatus($order); // now PAID
            $this->decrementStock((string) $order->id, $number);

            $this->logger->info('Order paid', ['order' => $number, 'ref' => $res['reference_id']]);
            return $this->result('success', $number, $res['reference_id'], 'پرداخت با موفقیت انجام شد.');
        });
    }

    private function decrementStock(string $orderId, string $number): void
    {
        foreach ($this->orders->itemsFor($orderId) as $item) {
            $s = $item['snapshot_data'];
            $lines = [['glass_size', $s['glass_size']['id'] ?? null, 1]];
            foreach (['plants' => 'plant', 'stones' => 'stone', 'figures' => 'figure'] as $key => $type) {
                foreach ($s[$key] ?? [] as $l) {
                    $lines[] = [$type, $l['id'], (int) $l['quantity']];
                }
            }
            foreach ($lines as [$type, $id, $qty]) {
                if ($id && !$this->catalog->decrementStock($type, (string) $id, $qty * $item['quantity'])) {
                    $this->logger->warning('Stock shortage after payment — please restock', ['order' => $number, 'type' => $type, 'id' => $id]);
                }
            }
        }
    }

    /** @return array{status: string, order_number: ?string, reference_id: ?string, message: string} */
    private function result(string $status, ?string $number, ?string $ref, string $message): array
    {
        return ['status' => $status, 'order_number' => $number, 'reference_id' => $ref, 'message' => $message];
    }
}

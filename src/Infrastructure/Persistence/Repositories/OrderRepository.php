<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Configurator\Configuration;
use Terrarium\Domain\Ordering\Order;
use Terrarium\Infrastructure\Persistence\Database;

final class OrderRepository
{
    public function __construct(private readonly Database $db) {}

    public function saveConfiguration(Configuration $c, array $snapshot): void
    {
        $now = Database::now();
        $this->db->insert('configurations', [
            'id' => $c->id,
            'user_id' => $c->userId,
            'glass_size_id' => $c->glassSize->id,
            'calculated_price_cents' => $c->calculatedPrice->amount,
            'is_valid' => $c->isValid,
            'snapshot_data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Persists a new order with its items.
     * @param array{recipient_name: string, recipient_phone: string, shipping_address: string, postal_code: ?string, customer_note: ?string} $shipping
     */
    public function create(Order $order, array $shipping, string $gateway): void
    {
        $now = Database::now();
        $this->db->insert('orders', [
            'id' => $order->id,
            'user_id' => $order->userId,
            'order_number' => $order->orderNumber,
            'status' => $order->status()->value,
            'total_price_cents' => $order->totalPrice->amount,
            'discount_cents' => $order->discount->amount,
            'shipping_cents' => $order->shipping->amount,
            'currency' => $order->totalPrice->currency,
            'payment_gateway' => $gateway,
            'recipient_name' => $shipping['recipient_name'],
            'recipient_phone' => $shipping['recipient_phone'],
            'shipping_address' => $shipping['shipping_address'],
            'postal_code' => $shipping['postal_code'],
            'customer_note' => $shipping['customer_note'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($order->items as $item) {
            $this->db->insert('order_items', [
                'id' => $item->id,
                'order_id' => $order->id,
                'configuration_id' => $item->configurationId,
                'unit_price_cents' => $item->unitPrice->amount,
                'quantity' => $item->quantity,
                'snapshot_data' => json_encode($item->snapshotData, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    public function findRow(string $id, bool $lock = false): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE id = :id' . ($lock ? $this->db->forUpdate() : ''), ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findRowByNumber(string $number): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE order_number = :n', ['n' => $number]);
    }

    public function toDomain(array $row): Order
    {
        $cur = (string) $row['currency'];
        return new Order(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            orderNumber: (string) $row['order_number'],
            status: OrderStatus::from((string) $row['status']),
            totalPrice: Money::fromCents((int) $row['total_price_cents'], $cur),
            discount: Money::fromCents((int) $row['discount_cents'], $cur),
            shipping: Money::fromCents((int) $row['shipping_cents'], $cur)
        );
    }

    public function updateStatus(Order $order): void
    {
        $params = ['s' => $order->status()->value, 'now' => Database::now(), 'id' => $order->id];
        $extra = '';
        if ($order->status() === OrderStatus::PAID) {
            $extra = ', paid_at = :paid';
            $params['paid'] = Database::now();
        }
        $this->db->query("UPDATE orders SET status = :s, updated_at = :now{$extra} WHERE id = :id", $params);
    }

    /** @return list<array<string, mixed>> */
    public function itemsFor(string $orderId): array
    {
        return array_map(function ($r) {
            $r['snapshot_data'] = json_decode((string) $r['snapshot_data'], true);
            $r['unit_price_cents'] = (int) $r['unit_price_cents'];
            $r['quantity'] = (int) $r['quantity'];
            return $r;
        }, $this->db->all('SELECT * FROM order_items WHERE order_id = :id', ['id' => $orderId]));
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(string $userId, int $limit = 50): array
    {
        return array_map([$this, 'present'], $this->db->all(
            'SELECT * FROM orders WHERE user_id = :u ORDER BY created_at DESC LIMIT ' . max(1, min(200, $limit)),
            ['u' => $userId]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listAll(?string $status = null, ?string $search = null, int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if ($status) {
            $where[] = 'o.status = :s';
            $params['s'] = $status;
        }
        if ($search) {
            $where[] = '(o.order_number LIKE :q1 OR u.mobile LIKE :q2 OR o.recipient_name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        $sql = 'SELECT o.*, u.mobile AS user_mobile FROM orders o JOIN users u ON u.id = o.user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY o.created_at DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        return array_map([$this, 'present'], $this->db->all($sql, $params));
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS c FROM orders GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public function paidRevenue(): int
    {
        return (int) ($this->db->first(
            "SELECT COALESCE(SUM(total_price_cents), 0) AS s FROM orders WHERE status IN ('paid', 'processing', 'completed')"
        )['s'] ?? 0);
    }

    /** @param array<string, mixed> $r */
    public function present(array $r): array
    {
        foreach (['total_price_cents', 'discount_cents', 'shipping_cents'] as $c) {
            $r[$c] = (int) $r[$c];
        }
        return $r;
    }
}

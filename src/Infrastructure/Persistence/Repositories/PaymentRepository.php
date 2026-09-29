<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Domain\Common\PaymentStatus;
use Terrarium\Infrastructure\Persistence\Database;

final class PaymentRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(string $orderId, string $gateway, int $amount, string $currency): string
    {
        $id = Database::uuid();
        $now = Database::now();
        $this->db->insert('payments', [
            'id' => $id,
            'order_id' => $orderId,
            'gateway' => $gateway,
            'amount_cents' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::PENDING->value,
            'authority' => null,
            'idempotency_key' => $orderId . ':' . bin2hex(random_bytes(8)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    public function setAuthority(string $paymentId, string $authority): void
    {
        $this->db->query('UPDATE payments SET authority = :a, updated_at = :now WHERE id = :id', [
            'a' => $authority, 'now' => Database::now(), 'id' => $paymentId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findByAuthority(string $gateway, string $authority, bool $lock = false): ?array
    {
        return $this->db->first(
            'SELECT * FROM payments WHERE gateway = :g AND authority = :a' . ($lock ? $this->db->forUpdate() : ''),
            ['g' => $gateway, 'a' => $authority]
        );
    }

    /** @param array<string, mixed> $response */
    public function markResult(string $paymentId, PaymentStatus $status, ?string $referenceId, array $response): void
    {
        $this->db->query(
            'UPDATE payments SET status = :s, reference_id = :r, gateway_response = :resp, verified_at = :v, updated_at = :now WHERE id = :id',
            [
                's' => $status->value,
                'r' => $referenceId,
                'resp' => json_encode($response, JSON_UNESCAPED_UNICODE),
                'v' => $status === PaymentStatus::SUCCESS ? Database::now() : null,
                'now' => Database::now(),
                'id' => $paymentId,
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(string $orderId): array
    {
        return array_map(function ($r) {
            $r['amount_cents'] = (int) $r['amount_cents'];
            unset($r['gateway_response']);
            return $r;
        }, $this->db->all('SELECT * FROM payments WHERE order_id = :o ORDER BY created_at DESC', ['o' => $orderId]));
    }
}

<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

final class BaleChatRepository
{
    public function __construct(private readonly Database $db) {}

    public function find(int $chatId): ?array
    {
        return $this->db->first('SELECT * FROM bale_chats WHERE chat_id = :c', ['c' => $chatId]);
    }

    public function touch(int $chatId, int $baleUserId, ?string $firstName): array
    {
        $row = $this->find($chatId);
        if ($row === null) {
            $this->db->insert('bale_chats', [
                'chat_id' => $chatId, 'bale_user_id' => $baleUserId, 'mobile' => null,
                'first_name' => $firstName !== null ? mb_substr($firstName, 0, 100) : null,
                'pending_payload' => null, 'created_at' => Database::now(), 'updated_at' => Database::now(),
            ]);
            return (array) $this->find($chatId);
        }
        return $row;
    }

    public function setMobile(int $chatId, string $mobile): void
    {
        $this->db->query('UPDATE bale_chats SET mobile = :m, updated_at = :n WHERE chat_id = :c', ['m' => $mobile, 'n' => Database::now(), 'c' => $chatId]);
    }

    public function setPending(int $chatId, ?string $payload): void
    {
        $this->db->query('UPDATE bale_chats SET pending_payload = :p, updated_at = :n WHERE chat_id = :c', ['p' => $payload, 'n' => Database::now(), 'c' => $chatId]);
    }

    public function count(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM bale_chats WHERE mobile IS NOT NULL')['c'] ?? 0);
    }

    /** Reserve a wallet transaction id for one payment. False if it was already used (replay). */
    public function claimTransaction(string $transactionId, string $paymentId): bool
    {
        try {
            $this->db->insert('bale_transactions', ['transaction_id' => $transactionId, 'payment_id' => $paymentId, 'created_at' => Database::now()]);
            return true;
        } catch (\PDOException) {
            $row = $this->db->first('SELECT payment_id FROM bale_transactions WHERE transaction_id = :t', ['t' => $transactionId]);
            return $row !== null && $row['payment_id'] === $paymentId; // same payment retried → fine
        }
    }
}

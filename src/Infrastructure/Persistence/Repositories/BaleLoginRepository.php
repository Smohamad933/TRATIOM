<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

/** Website login requests confirmed inside the Bale bot. Status: pending → approved → used | denied. */
final class BaleLoginRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(string $token, ?string $mobile, string $ip, int $ttlSeconds): void
    {
        $this->db->insert('bale_logins', [
            'token' => $token, 'status' => 'pending', 'mobile' => $mobile, 'chat_id' => null, 'ip' => mb_substr($ip, 0, 45),
            'created_at' => Database::now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
    }

    public function find(string $token): ?array
    {
        return $this->db->first('SELECT * FROM bale_logins WHERE token = :t', ['t' => $token]);
    }

    public static function expired(array $row): bool
    {
        return strtotime($row['expires_at'] . ' UTC') < time();
    }

    public function recentFromIp(string $ip, int $seconds): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM bale_logins WHERE ip = :i AND created_at > :t', ['i' => $ip, 't' => gmdate('Y-m-d H:i:s', time() - $seconds)])['c'] ?? 0);
    }

    /** pending → approved|denied, only once. */
    public function decide(string $token, bool $approve, string $mobile, int $chatId): bool
    {
        return $this->db->query(
            'UPDATE bale_logins SET status = :s, mobile = :m, chat_id = :c WHERE token = :t AND status = :p AND expires_at > :n',
            ['s' => $approve ? 'approved' : 'denied', 'm' => $mobile, 'c' => $chatId, 't' => $token, 'p' => 'pending', 'n' => Database::now()]
        )->rowCount() === 1;
    }

    /** approved → used, only once (the browser gets exactly one session). */
    public function consume(string $token): bool
    {
        return $this->db->query('UPDATE bale_logins SET status = :u WHERE token = :t AND status = :a', ['u' => 'used', 't' => $token, 'a' => 'approved'])->rowCount() === 1;
    }

    public function purge(): void
    {
        $this->db->query('DELETE FROM bale_logins WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    }
}

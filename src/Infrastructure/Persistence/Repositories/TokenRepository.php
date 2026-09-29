<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

/**
 * Opaque bearer tokens. Only a SHA-256 hash is stored, so a DB leak does not leak sessions.
 */
final class TokenRepository
{
    public function __construct(private readonly Database $db) {}

    public function issue(string $userId, int $ttlDays): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->db->insert('api_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400),
            'created_at' => Database::now(),
        ]);
        return $plain;
    }

    /** @return array<string, mixed>|null user row */
    public function userForToken(string $plain): ?array
    {
        $row = $this->db->first(
            'SELECT u.*, t.id AS token_id FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = :h AND t.expires_at > :now',
            ['h' => hash('sha256', $plain), 'now' => Database::now()]
        );
        if ($row === null) {
            return null;
        }
        $this->db->query('UPDATE api_tokens SET last_used_at = :now WHERE id = :id', ['now' => Database::now(), 'id' => $row['token_id']]);
        $row['is_admin'] = (bool) $row['is_admin'];
        return $row;
    }

    public function revoke(string $plain): void
    {
        $this->db->query('DELETE FROM api_tokens WHERE token_hash = :h', ['h' => hash('sha256', $plain)]);
    }

    public function purgeExpired(): int
    {
        return $this->db->query('DELETE FROM api_tokens WHERE expires_at <= :now', ['now' => Database::now()])->rowCount();
    }
}

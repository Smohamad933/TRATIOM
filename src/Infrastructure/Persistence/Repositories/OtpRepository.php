<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

final class OtpRepository
{
    public function __construct(private readonly Database $db) {}

    public function create(string $mobile, string $codeHash, string $expiresAt, string $ip): void
    {
        // Invalidate previous unused codes for this number
        $this->db->query('UPDATE otp_codes SET is_used = 1 WHERE mobile = :m AND is_used = 0', ['m' => $mobile]);
        $this->db->insert('otp_codes', [
            'mobile' => $mobile, 'code_hash' => $codeHash, 'expires_at' => $expiresAt,
            'attempts' => 0, 'is_used' => 0, 'ip_address' => $ip, 'created_at' => Database::now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function latestUnused(string $mobile): ?array
    {
        return $this->db->first(
            'SELECT * FROM otp_codes WHERE mobile = :m AND is_used = 0 ORDER BY id DESC LIMIT 1',
            ['m' => $mobile]
        );
    }

    /** @return array<string, mixed>|null */
    public function latest(string $mobile): ?array
    {
        return $this->db->first('SELECT * FROM otp_codes WHERE mobile = :m ORDER BY id DESC LIMIT 1', ['m' => $mobile]);
    }

    public function incrementAttempts(int $id): void
    {
        $this->db->query('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id', ['id' => $id]);
    }

    /** Marks the code used; returns false if it was already consumed (race-safe). */
    public function markUsed(int $id): bool
    {
        return $this->db->query('UPDATE otp_codes SET is_used = 1 WHERE id = :id AND is_used = 0', ['id' => $id])->rowCount() > 0;
    }

    public function countForMobileSince(string $mobile, string $since): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM otp_codes WHERE mobile = :m AND created_at >= :s', ['m' => $mobile, 's' => $since])['c'] ?? 0);
    }

    public function countForIpSince(string $ip, string $since): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM otp_codes WHERE ip_address = :ip AND created_at >= :s', ['ip' => $ip, 's' => $since])['c'] ?? 0);
    }

    public function purgeOlderThan(string $before): int
    {
        return $this->db->query('DELETE FROM otp_codes WHERE created_at < :b', ['b' => $before])->rowCount();
    }
}

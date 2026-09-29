<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

final class UserRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->cast($this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $id]));
    }

    /** @return array<string, mixed>|null */
    public function findByMobile(string $mobile): ?array
    {
        return $this->cast($this->db->first('SELECT * FROM users WHERE mobile = :m', ['m' => $mobile]));
    }

    /** @return array<string, mixed> */
    public function findOrCreate(string $mobile): array
    {
        $existing = $this->findByMobile($mobile);
        if ($existing) {
            return $existing;
        }
        $now = Database::now();
        $this->db->insert('users', [
            'id' => Database::uuid(), 'mobile' => $mobile, 'is_admin' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return (array) $this->findByMobile($mobile);
    }

    public function updateProfile(string $id, ?string $firstName, ?string $lastName): void
    {
        $this->db->query(
            'UPDATE users SET first_name = :f, last_name = :l, updated_at = :now WHERE id = :id',
            ['f' => $firstName, 'l' => $lastName, 'now' => Database::now(), 'id' => $id]
        );
    }

    public function setAdmin(string $mobile, bool $isAdmin): void
    {
        $user = $this->findOrCreate($mobile);
        $this->db->query('UPDATE users SET is_admin = :a, updated_at = :now WHERE id = :id', [
            'a' => $isAdmin, 'now' => Database::now(), 'id' => $user['id'],
        ]);
    }

    public function count(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    }

    /** @param array<string, mixed>|null $row */
    private function cast(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['is_admin'] = (bool) $row['is_admin'];
        return $row;
    }
}

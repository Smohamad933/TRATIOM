<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use Terrarium\Infrastructure\Persistence\Database;

/** Ready-made terrariums the customer can buy as-is or use as a starting point. */
final class PresetRepository
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array<string, mixed>> */
    public function list(bool $activeOnly): array
    {
        $rows = $this->db->all('SELECT * FROM presets' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, created_at ASC');
        return array_map([$this, 'present'], $rows);
    }

    public function find(string $id): ?array
    {
        $r = $this->db->first('SELECT * FROM presets WHERE id = :id', ['id' => $id]);
        return $r ? $this->present($r) : null;
    }

    /** @param array<string, mixed> $data */
    public function save(?string $id, array $data): string
    {
        $row = [
            'name' => $data['name'],
            'description' => $data['description'],
            'image_url' => $data['image_url'],
            'configuration' => json_encode($data['configuration'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => (int) $data['sort_order'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'updated_at' => Database::now(),
        ];
        if ($id === null) {
            $row['id'] = $id = Database::uuid();
            $row['created_at'] = $row['updated_at'];
            $this->db->insert('presets', $row);
            return $id;
        }
        $sets = implode(', ', array_map(fn ($k) => "{$k} = :{$k}", array_keys($row)));
        $this->db->query("UPDATE presets SET {$sets} WHERE id = :id", $row + ['id' => $id]);
        return $id;
    }

    public function delete(string $id): bool
    {
        return $this->db->query('DELETE FROM presets WHERE id = :id', ['id' => $id])->rowCount() > 0;
    }

    private function present(array $r): array
    {
        $cfg = json_decode((string) $r['configuration'], true);
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'image_url' => $r['image_url'],
            'configuration' => is_array($cfg) ? $cfg : ['glass_size_id' => null, 'plants' => [], 'stones' => [], 'figures' => []],
            'sort_order' => (int) $r['sort_order'],
            'is_active' => (bool) $r['is_active'],
        ];
    }
}

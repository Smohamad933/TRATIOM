<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence\Repositories;

use InvalidArgumentException;
use Terrarium\Domain\Catalog\CompatibilityRule;
use Terrarium\Domain\Catalog\Figure;
use Terrarium\Domain\Catalog\GlassSize;
use Terrarium\Domain\Catalog\Plant;
use Terrarium\Domain\Catalog\Stone;
use Terrarium\Domain\Common\LightLevel;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\Volume;
use Terrarium\Infrastructure\Persistence\Database;

final class CatalogRepository
{
    /** component type => table */
    public const TABLES = [
        'glass_size' => 'glass_sizes',
        'plant' => 'plants',
        'stone' => 'stones',
        'figure' => 'figures',
    ];

    /** Writable columns per component type (besides id/timestamps). */
    public const FIELDS = [
        'glass_size' => ['name', 'code', 'total_volume_ml', 'usable_volume_ml', 'max_plant_capacity', 'is_closed_ecosystem', 'price_cents', 'stock_quantity', 'is_active', 'image_url', 'width_cm', 'depth_cm', 'height_cm'],
        'plant' => ['name', 'scientific_name', 'volume_occupancy_ml', 'light_level', 'moisture_level', 'tolerates_closed_glass', 'price_cents', 'stock_quantity', 'is_active', 'image_url'],
        'stone' => ['name', 'type', 'volume_per_unit_ml', 'price_cents', 'stock_quantity', 'is_active', 'image_url'],
        'figure' => ['name', 'volume_occupancy_ml', 'price_cents', 'stock_quantity', 'is_active', 'image_url'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $currency = 'IRR'
    ) {}

    public static function table(string $type): string
    {
        return self::TABLES[$type] ?? throw new InvalidArgumentException("Unknown component type: {$type}");
    }

    /** @return list<array<string, mixed>> */
    public function listRows(string $type, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM ' . self::table($type) . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY price_cents ASC, name ASC';
        return array_map(fn ($r) => $this->normalize($type, $r), $this->db->all($sql));
    }

    /** @return array<string, mixed>|null */
    public function findRow(string $type, string $id): ?array
    {
        $row = $this->db->first('SELECT * FROM ' . self::table($type) . ' WHERE id = :id', ['id' => $id]);
        return $row ? $this->normalize($type, $row) : null;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> keyed by id
     */
    public function findRows(string $type, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all('SELECT * FROM ' . self::table($type) . " WHERE id IN ({$placeholders})", $ids);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['id']] = $this->normalize($type, $r);
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function create(string $type, array $data): string
    {
        $row = array_intersect_key($data, array_flip(self::FIELDS[$type]));
        $row['id'] = Database::uuid();
        $row['created_at'] = $row['updated_at'] = Database::now();
        $this->db->insert(self::table($type), $row);
        return $row['id'];
    }

    /** @param array<string, mixed> $data */
    public function update(string $type, string $id, array $data): bool
    {
        $row = array_intersect_key($data, array_flip(self::FIELDS[$type]));
        if ($row === []) {
            return false;
        }
        $row['updated_at'] = Database::now();
        $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($row)));
        $row['id'] = $id;
        return $this->db->query('UPDATE ' . self::table($type) . " SET {$sets} WHERE id = :id", $row)->rowCount() > 0;
    }

    /** Atomically decrements stock; returns false when not enough stock is left. */
    public function decrementStock(string $type, string $id, int $qty): bool
    {
        return $this->db->query(
            'UPDATE ' . self::table($type) . ' SET stock_quantity = stock_quantity - :q, updated_at = :now WHERE id = :id AND stock_quantity >= :q2',
            ['q' => $qty, 'q2' => $qty, 'now' => Database::now(), 'id' => $id]
        )->rowCount() > 0;
    }

    // ---------------------------------------------------------------- rules

    /** @return list<array<string, mixed>> */
    public function listRuleRows(bool $activeOnly = false): array
    {
        $rows = $this->db->all('SELECT * FROM compatibility_rules' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY priority ASC, created_at DESC');
        return array_map(function ($r) {
            $r['is_compatible'] = (bool) $r['is_compatible'];
            $r['is_active'] = (bool) $r['is_active'];
            $r['priority'] = (int) $r['priority'];
            return $r;
        }, $rows);
    }

    /** @return list<CompatibilityRule> */
    public function activeRules(): array
    {
        return array_map(fn ($r) => new CompatibilityRule(
            id: (string) $r['id'],
            ruleType: (string) $r['rule_type'],
            sourceType: (string) $r['source_type'],
            sourceId: $r['source_id'] !== null ? (string) $r['source_id'] : null,
            targetType: (string) $r['target_type'],
            targetId: $r['target_id'] !== null ? (string) $r['target_id'] : null,
            isCompatible: (bool) $r['is_compatible'],
            reasonMessage: (string) $r['reason_message'],
            priority: (int) $r['priority']
        ), $this->listRuleRows(true));
    }

    /** @param array<string, mixed> $data */
    public function createRule(array $data): string
    {
        $id = Database::uuid();
        $now = Database::now();
        $this->db->insert('compatibility_rules', [
            'id' => $id,
            'rule_type' => $data['rule_type'],
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'is_compatible' => 0,
            'reason_message' => $data['reason_message'],
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    public function setRuleActive(string $id, bool $active): bool
    {
        return $this->db->query(
            'UPDATE compatibility_rules SET is_active = :a, updated_at = :now WHERE id = :id',
            ['a' => $active, 'now' => Database::now(), 'id' => $id]
        )->rowCount() > 0;
    }

    public function deleteRule(string $id): bool
    {
        return $this->db->query('DELETE FROM compatibility_rules WHERE id = :id', ['id' => $id])->rowCount() > 0;
    }

    // ---------------------------------------------------------------- hydration

    /** @param array<string, mixed> $r */
    public function toGlass(array $r): GlassSize
    {
        return new GlassSize(
            id: $r['id'], name: $r['name'], code: $r['code'],
            totalVolume: Volume::fromMl($r['total_volume_ml']),
            usableVolume: Volume::fromMl($r['usable_volume_ml']),
            maxPlantCapacity: $r['max_plant_capacity'],
            isClosedEcosystem: $r['is_closed_ecosystem'],
            price: Money::fromCents($r['price_cents'], $this->currency),
            isActive: $r['is_active']
        );
    }

    /** @param array<string, mixed> $r */
    public function toPlant(array $r): Plant
    {
        return new Plant(
            id: $r['id'], name: $r['name'], scientificName: $r['scientific_name'],
            volumeOccupancy: Volume::fromMl($r['volume_occupancy_ml']),
            lightLevel: LightLevel::from($r['light_level']),
            moistureLevel: MoistureLevel::from($r['moisture_level']),
            toleratesClosedGlass: $r['tolerates_closed_glass'],
            price: Money::fromCents($r['price_cents'], $this->currency),
            stockQuantity: $r['stock_quantity'], isActive: $r['is_active']
        );
    }

    /** @param array<string, mixed> $r */
    public function toStone(array $r): Stone
    {
        return new Stone(
            id: $r['id'], name: $r['name'], type: $r['type'],
            volumePerUnit: Volume::fromMl($r['volume_per_unit_ml']),
            price: Money::fromCents($r['price_cents'], $this->currency),
            stockQuantity: $r['stock_quantity'], isActive: $r['is_active']
        );
    }

    /** @param array<string, mixed> $r */
    public function toFigure(array $r): Figure
    {
        return new Figure(
            id: $r['id'], name: $r['name'],
            volumeOccupancy: Volume::fromMl($r['volume_occupancy_ml']),
            price: Money::fromCents($r['price_cents'], $this->currency),
            stockQuantity: $r['stock_quantity'], isActive: $r['is_active']
        );
    }

    /**
     * Casts DB values (strings on MySQL) to proper PHP types.
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function normalize(string $type, array $r): array
    {
        $ints = ['total_volume_ml', 'usable_volume_ml', 'max_plant_capacity', 'price_cents', 'stock_quantity', 'volume_occupancy_ml', 'volume_per_unit_ml'];
        $bools = ['is_closed_ecosystem', 'is_active', 'tolerates_closed_glass'];
        foreach ($ints as $c) {
            if (array_key_exists($c, $r)) {
                $r[$c] = (int) $r[$c];
            }
        }
        foreach ($bools as $c) {
            if (array_key_exists($c, $r)) {
                $r[$c] = (bool) $r[$c];
            }
        }
        $r['type_key'] = $type;
        return $r;
    }
}

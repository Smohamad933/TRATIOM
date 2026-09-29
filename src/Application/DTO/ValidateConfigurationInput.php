<?php

declare(strict_types=1);

namespace Terrarium\Application\DTO;

use Terrarium\Application\Exceptions\ValidationException;

final readonly class ValidateConfigurationInput
{
    public const MAX_QUANTITY_PER_ITEM = 50;

    /**
     * @param string $glassSizeId
     * @param list<array{id: string, quantity: int}> $plants
     * @param list<array{id: string, quantity: int}> $stones
     * @param list<array{id: string, quantity: int}> $figures
     */
    public function __construct(
        public string $glassSizeId,
        public array $plants = [],
        public array $stones = [],
        public array $figures = []
    ) {}

    /**
     * Builds the DTO from a request payload:
     * { "glass_size_id": "...", "plants": [{"id": "...", "quantity": 2}], "stones": [...], "figures": [...] }
     * Duplicate ids are merged. Accepts "plant_id"/"stone_id"/"figure_id" as aliases of "id".
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $glass = $data['glass_size_id'] ?? null;
        if (!is_string($glass) || trim($glass) === '') {
            throw new ValidationException('انتخاب ظرف شیشه‌ای الزامی است.', ['field' => 'glass_size_id']);
        }

        return new self(
            trim($glass),
            self::items($data['plants'] ?? [], 'plants', 'plant_id'),
            self::items($data['stones'] ?? [], 'stones', 'stone_id'),
            self::items($data['figures'] ?? [], 'figures', 'figure_id')
        );
    }

    /** @return list<array{id: string, quantity: int}> */
    private static function items(mixed $raw, string $field, string $alias): array
    {
        if (!is_array($raw)) {
            throw new ValidationException("فرمت فیلد {$field} نامعتبر است.", ['field' => $field]);
        }
        $merged = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new ValidationException("فرمت فیلد {$field} نامعتبر است.", ['field' => $field]);
            }
            $id = $item['id'] ?? $item[$alias] ?? null;
            $qty = $item['quantity'] ?? 1;
            if (!is_string($id) || $id === '') {
                throw new ValidationException("شناسه یکی از اقلام {$field} نامعتبر است.", ['field' => $field]);
            }
            if (!is_int($qty) && !(is_string($qty) && ctype_digit($qty))) {
                throw new ValidationException('تعداد باید عدد صحیح باشد.', ['field' => $field]);
            }
            $qty = (int) $qty;
            if ($qty < 1 || $qty > self::MAX_QUANTITY_PER_ITEM) {
                throw new ValidationException('تعداد هر قلم باید بین ۱ تا ' . self::MAX_QUANTITY_PER_ITEM . ' باشد.', ['field' => $field]);
            }
            $merged[$id] = ($merged[$id] ?? 0) + $qty;
        }
        $out = [];
        foreach ($merged as $id => $qty) {
            $out[] = ['id' => (string) $id, 'quantity' => min($qty, self::MAX_QUANTITY_PER_ITEM)];
        }
        return $out;
    }

    /** @return array<string, int> "type:id" => quantity (including the glass itself) */
    public function stockRequirements(): array
    {
        $req = ['glass_size:' . $this->glassSizeId => 1];
        foreach (['plant' => $this->plants, 'stone' => $this->stones, 'figure' => $this->figures] as $type => $items) {
            foreach ($items as $i) {
                $req[$type . ':' . $i['id']] = $i['quantity'];
            }
        }
        return $req;
    }
}

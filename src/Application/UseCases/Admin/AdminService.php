<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Admin;

use DomainException;
use PDOException;
use Terrarium\Application\Exceptions\NotFoundException;
use Terrarium\Application\Exceptions\ValidationException;
use Terrarium\Domain\Common\LightLevel;
use Terrarium\Domain\Common\MoistureLevel;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Repositories\CatalogRepository;
use Terrarium\Infrastructure\Persistence\Repositories\OrderRepository;
use Terrarium\Infrastructure\Persistence\Repositories\PaymentRepository;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;

/**
 * Live admin control over catalog, pricing, stock, compatibility rules and orders.
 */
final class AdminService
{
    private const INT_FIELDS = ['total_volume_ml', 'usable_volume_ml', 'max_plant_capacity', 'price_cents', 'stock_quantity', 'volume_occupancy_ml', 'volume_per_unit_ml'];
    private const BOOL_FIELDS = ['is_closed_ecosystem', 'is_active', 'tolerates_closed_glass'];
    private const REQUIRED = [
        'glass_size' => ['name', 'code', 'total_volume_ml', 'usable_volume_ml', 'max_plant_capacity', 'price_cents'],
        'plant' => ['name', 'volume_occupancy_ml', 'light_level', 'moisture_level', 'price_cents'],
        'stone' => ['name', 'type', 'volume_per_unit_ml', 'price_cents'],
        'figure' => ['name', 'volume_occupancy_ml', 'price_cents'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly CatalogRepository $catalog,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly UserRepository $users
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function catalog(): array
    {
        $out = [];
        foreach (array_keys(CatalogRepository::TABLES) as $type) {
            $out[$type] = $this->catalog->listRows($type, false);
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function createItem(string $type, array $input): array
    {
        $this->assertType($type);
        $data = $this->sanitize($type, $input);
        foreach (self::REQUIRED[$type] as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) {
                throw new ValidationException("فیلد {$f} الزامی است.", ['field' => $f]);
            }
        }
        $data += ['stock_quantity' => 0, 'is_active' => true];
        $this->assertConsistent($type, $data);
        try {
            $id = $this->catalog->create($type, $data);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                throw new ValidationException('کد تکراری است.', ['field' => 'code']);
            }
            throw $e;
        }
        return (array) $this->catalog->findRow($type, $id);
    }

    /** @param array<string, mixed> $input */
    public function updateItem(string $type, string $id, array $input): array
    {
        $this->assertType($type);
        $current = $this->catalog->findRow($type, $id) ?? throw new NotFoundException('قطعه یافت نشد.');
        $data = $this->sanitize($type, $input);
        if ($data === []) {
            throw new ValidationException('هیچ فیلد قابل ویرایشی ارسال نشده است.');
        }
        $this->assertConsistent($type, array_merge($current, $data));
        try {
            $this->catalog->update($type, $id, $data);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                throw new ValidationException('کد تکراری است.', ['field' => 'code']);
            }
            throw $e;
        }
        return (array) $this->catalog->findRow($type, $id);
    }

    // ------------------------------------------------------------------ rules

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return $this->catalog->listRuleRows(false);
    }

    /**
     * Supported rule shapes (evaluated by CompatibilityEngine):
     *  - plant ↔ glass_size : this plant cannot go in this glass (target empty = any glass)
     *  - plant ↔ plant      : these two plants cannot be combined
     * @param array<string, mixed> $input
     */
    public function createRule(array $input): array
    {
        $sourceType = (string) ($input['source_type'] ?? 'plant');
        $targetType = (string) ($input['target_type'] ?? '');
        $sourceId = isset($input['source_id']) && $input['source_id'] !== '' ? (string) $input['source_id'] : null;
        $targetId = isset($input['target_id']) && $input['target_id'] !== '' ? (string) $input['target_id'] : null;
        $message = trim((string) ($input['reason_message'] ?? ''));

        if ($sourceType !== 'plant' || !in_array($targetType, ['plant', 'glass_size'], true)) {
            throw new ValidationException('نوع قانون پشتیبانی نمی‌شود. (گیاه ↔ گیاه یا گیاه ↔ ظرف)');
        }
        if ($sourceId === null || !$this->catalog->findRow('plant', $sourceId)) {
            throw new ValidationException('گیاه مبدأ را انتخاب کنید.', ['field' => 'source_id']);
        }
        if ($targetType === 'plant' && ($targetId === null || $targetId === $sourceId)) {
            throw new ValidationException('گیاه مقصد را انتخاب کنید (متفاوت با گیاه مبدأ).', ['field' => 'target_id']);
        }
        if ($targetId !== null && !$this->catalog->findRow($targetType, $targetId)) {
            throw new ValidationException('مقصد انتخاب‌شده یافت نشد.', ['field' => 'target_id']);
        }
        if ($message === '' || mb_strlen($message) > 500) {
            throw new ValidationException('پیام خطا برای مشتری الزامی است (حداکثر ۵۰۰ کاراکتر).', ['field' => 'reason_message']);
        }

        $id = $this->catalog->createRule([
            'rule_type' => $targetType === 'plant' ? 'PLANT_PLANT_CONFLICT' : 'PLANT_GLASS_CONFLICT',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason_message' => $message,
            'priority' => (int) ($input['priority'] ?? 100),
        ]);
        foreach ($this->rules() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return ['id' => $id];
    }

    public function setRuleActive(string $id, bool $active): void
    {
        if (!$this->catalog->setRuleActive($id, $active)) {
            throw new NotFoundException('قانون یافت نشد.');
        }
    }

    public function deleteRule(string $id): void
    {
        if (!$this->catalog->deleteRule($id)) {
            throw new NotFoundException('قانون یافت نشد.');
        }
    }

    // ------------------------------------------------------------------ orders

    /** @return list<array<string, mixed>> */
    public function orders(?string $status, ?string $search, int $page = 1): array
    {
        if ($status !== null && $status !== '' && OrderStatus::tryFrom($status) === null) {
            throw new ValidationException('وضعیت نامعتبر است.');
        }
        $limit = 50;
        return $this->orders->listAll($status ?: null, $search ?: null, $limit, max(0, $page - 1) * $limit);
    }

    /** @return array<string, mixed> */
    public function order(string $id): array
    {
        $row = $this->orders->findRow($id) ?? throw new NotFoundException('سفارش یافت نشد.');
        $row = $this->orders->present($row);
        $user = $this->users->findById((string) $row['user_id']);
        $row['user_mobile'] = $user['mobile'] ?? null;
        $row['items'] = $this->orders->itemsFor($id);
        $row['payments'] = $this->payments->forOrder($id);
        $row['allowed_transitions'] = $this->allowedTransitions(OrderStatus::from((string) $row['status']));
        return $row;
    }

    /** @return array<string, mixed> */
    public function changeOrderStatus(string $id, string $newStatus): array
    {
        $target = OrderStatus::tryFrom($newStatus) ?? throw new ValidationException('وضعیت نامعتبر است.');
        if ($target === OrderStatus::PAID) {
            throw new ValidationException('وضعیت «پرداخت‌شده» فقط از طریق تأیید درگاه پرداخت ثبت می‌شود.');
        }
        $this->db->transaction(function () use ($id, $target) {
            $row = $this->orders->findRow($id, true) ?? throw new NotFoundException('سفارش یافت نشد.');
            $order = $this->orders->toDomain($row);
            try {
                $order->transitionTo($target);
            } catch (DomainException) {
                throw new ValidationException("تغییر وضعیت از «{$row['status']}» به «{$target->value}» مجاز نیست.");
            }
            $this->orders->updateStatus($order);
        });
        return $this->order($id);
    }

    /** @return list<string> */
    public function allowedTransitions(OrderStatus $s): array
    {
        return array_values(array_map(
            fn (OrderStatus $t) => $t->value,
            array_filter(OrderStatus::cases(), fn (OrderStatus $t) => $t !== OrderStatus::PAID && $s->canTransitionTo($t))
        ));
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $low = [];
        foreach (array_keys(CatalogRepository::TABLES) as $type) {
            foreach ($this->catalog->listRows($type, true) as $r) {
                if ($r['stock_quantity'] <= 5) {
                    $low[] = ['type' => $type, 'id' => $r['id'], 'name' => $r['name'], 'stock' => $r['stock_quantity']];
                }
            }
        }
        return [
            'orders_by_status' => $this->orders->countsByStatus(),
            'revenue_cents' => $this->orders->paidRevenue(),
            'users' => $this->users->count(),
            'low_stock' => $low,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function assertType(string $type): void
    {
        if (!isset(CatalogRepository::TABLES[$type])) {
            throw new NotFoundException('نوع قطعه نامعتبر است.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function sanitize(string $type, array $input): array
    {
        $out = [];
        foreach (CatalogRepository::FIELDS[$type] as $f) {
            if (!array_key_exists($f, $input)) {
                continue;
            }
            $v = $input[$f];
            if (in_array($f, self::INT_FIELDS, true)) {
                if (!is_numeric($v) || (float) $v !== floor((float) $v) || (int) $v < 0) {
                    throw new ValidationException("مقدار {$f} باید عدد صحیح غیرمنفی باشد.", ['field' => $f]);
                }
                $v = (int) $v;
                if ($v > 1_000_000_000_000) {
                    throw new ValidationException("مقدار {$f} بیش از حد بزرگ است.", ['field' => $f]);
                }
            } elseif (in_array($f, self::BOOL_FIELDS, true)) {
                $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            } elseif ($f === 'light_level') {
                $v = (LightLevel::tryFrom((string) $v) ?? throw new ValidationException('سطح نور نامعتبر است.', ['field' => $f]))->value;
            } elseif ($f === 'moisture_level') {
                $v = (MoistureLevel::tryFrom((string) $v) ?? throw new ValidationException('سطح رطوبت نامعتبر است.', ['field' => $f]))->value;
            } else {
                $v = $v === null ? null : trim((string) $v);
                if ($v !== null && mb_strlen($v) > 150) {
                    throw new ValidationException("مقدار {$f} بیش از حد طولانی است.", ['field' => $f]);
                }
                if ($f === 'scientific_name' && $v === '') {
                    $v = null;
                }
            }
            $out[$f] = $v;
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    private function assertConsistent(string $type, array $data): void
    {
        if ($type === 'glass_size' && isset($data['usable_volume_ml'], $data['total_volume_ml']) && $data['usable_volume_ml'] > $data['total_volume_ml']) {
            throw new ValidationException('حجم مفید نمی‌تواند از حجم کل بیشتر باشد.', ['field' => 'usable_volume_ml']);
        }
        if (array_key_exists('name', $data) && ($data['name'] === '' || $data['name'] === null)) {
            throw new ValidationException('نام الزامی است.', ['field' => 'name']);
        }
    }
}

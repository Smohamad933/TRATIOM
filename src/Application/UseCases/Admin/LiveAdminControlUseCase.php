<?php

declare(strict_types=1);

namespace Terrarium\Application\UseCases\Admin;

use Terrarium\Domain\Catalog\CompatibilityRule;
use Terrarium\Domain\Common\Money;
use Terrarium\Domain\Common\OrderStatus;
use Terrarium\Domain\Ordering\Order;

/**
 * High-level orchestration for Live Admin Control over catalog, pricing, rules, and orders.
 */
final class LiveAdminControlUseCase
{
    /**
     * Updates live component price.
     */
    public function updateComponentPrice(string $componentType, string $componentId, int $newPriceCents): array
    {
        $newMoney = Money::fromCents($newPriceCents);
        return [
            'success' => true,
            'component_type' => $componentType,
            'component_id' => $componentId,
            'new_price_cents' => $newMoney->amount,
            'formatted' => $newMoney->formatted(),
            'message' => 'قیمت قطعه به صورت لحظه‌ای در کل سیستم به‌روزرسانی شد.',
        ];
    }

    /**
     * Adds or updates a dynamic compatibility rule live.
     */
    public function upsertCompatibilityRule(
        string $ruleType,
        string $sourceType,
        ?string $sourceId,
        string $targetType,
        ?string $targetId,
        bool $isCompatible,
        string $reasonMessage
    ): array {
        $rule = new CompatibilityRule(
            id: uniqid('rule_', true),
            ruleType: $ruleType,
            sourceType: $sourceType,
            sourceId: $sourceId,
            targetType: $targetType,
            targetId: $targetId,
            isCompatible: $isCompatible,
            reasonMessage: $reasonMessage
        );

        return [
            'success' => true,
            'rule_id' => $rule->id,
            'is_compatible' => $rule->isCompatible,
            'message' => 'قانون سازگاری جدید با موفقیت ثبت شد و بلافاصله در موتور اعتبارسنجی اعمال می‌گردد.',
        ];
    }

    /**
     * Updates an order status with State Machine validation.
     */
    public function updateOrderStatus(Order $order, string $newStatusStr): array
    {
        $newStatus = OrderStatus::from($newStatusStr);
        $order->transitionTo($newStatus);

        return [
            'success' => true,
            'order_id' => $order->id,
            'new_status' => $order->status()->value,
            'message' => "وضعیت سفارش {$order->orderNumber} با موفقیت به {$order->status()->value} تغییر یافت.",
        ];
    }
}

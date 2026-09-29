<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

enum LightLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case BRIGHT = 'bright';
}

enum MoistureLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::PENDING_PAYMENT, self::CANCELLED], true),
            self::PENDING_PAYMENT => in_array($target, [self::PAID, self::FAILED, self::CANCELLED], true),
            self::PAID => in_array($target, [self::PROCESSING, self::CANCELLED], true),
            self::PROCESSING => in_array($target, [self::COMPLETED, self::CANCELLED], true),
            self::COMPLETED, self::CANCELLED, self::FAILED => false,
        };
    }
}

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
}

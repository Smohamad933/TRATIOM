<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
}

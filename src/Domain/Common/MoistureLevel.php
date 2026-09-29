<?php

declare(strict_types=1);

namespace Terrarium\Domain\Common;

enum MoistureLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}
